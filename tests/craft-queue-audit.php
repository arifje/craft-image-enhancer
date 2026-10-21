<?php

declare(strict_types=1);
use arjanbrinkman\craftimageenhancer\helpers\AssetHelper;
use arjanbrinkman\craftimageenhancer\helpers\JobStatus;
use arjanbrinkman\craftimageenhancer\jobs\ArticleImageEnhancementJob;
use arjanbrinkman\craftimageenhancer\jobs\ArticleImageFaceBlurJob;
use arjanbrinkman\craftimageenhancer\jobs\ArticleImageVideoJob;
use arjanbrinkman\craftimageenhancer\ImageEnhancer;

$root='/tmp/image-enhancer-audit-20261020';
if(getenv('IE_AUDIT_SITE')!==$root.'/site')throw new RuntimeException('Wrong audit site');
require $root.'/site/bootstrap.php';$app=require CRAFT_VENDOR_PATH.'/craftcms/cms/bootstrap/console.php';
if($app->getDb()->createCommand('SELECT DATABASE()')->queryScalar()!=='image_enhancer_audit_20261020')throw new RuntimeException('Wrong DB');
$f=json_decode(file_get_contents($root.'/fixture.json'),true,512,JSON_THROW_ON_ERROR);$checks=0;
$assert=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;echo "PASS: $message\n";};
$plugin=ImageEnhancer::getInstance();$asset=$app->getAssets()->getAssetById($f['assetId']);
$assert((new ReflectionClass($plugin))->getFileName()===$root.'/plugin/src/ImageEnhancer.php','Exact Documents source loaded');
foreach ([1,2,3,4,5,6,7,8] as $orientation) {
 $image=new Imagick();$image->newImage(30,10,new ImagickPixel('blue'));$image->setImageOrientation($orientation);
 \arjanbrinkman\craftimageenhancer\helpers\ImageHelper::autoOrient($image);
 $swapped=$orientation>=5;
 $assert($image->getImageWidth()===($swapped?10:30)&&$image->getImageHeight()===($swapped?30:10)&&$image->getImageOrientation()===Imagick::ORIENTATION_TOPLEFT,'Native EXIF orientation '.$orientation.' normalizes dimensions and tag');
 $image->clear();
}
$run=static function($class,$props=[],$mode='success',$cancel=false)use($app,$f){
 $GLOBALS['ieAuditMode']=$mode;$token=bin2hex(random_bytes(16));$GLOBALS['ieAuditToken']=$token;$GLOBALS['ieAuditAssetId']=$f['assetId'];
 JobStatus::merge($token,$f['assetId'],['token'=>$token,'assetId'=>$f['assetId'],'userId'=>$f['users']['writer'],'status'=>$cancel?'canceled':'queued']);
 $job=new $class(array_merge(['assetId'=>$f['assetId'],'userId'=>$f['users']['writer'],'token'=>$token],$props));$id=$app->getQueue()->push($job);
 $app->getQueue()->executeJob($id);$s=JobStatus::get($token);
 return $s;
};
foreach([
 [ArticleImageEnhancementJob::class,['imageEnhancementProvider'=>'openai','imageEnhancementModel'=>'gpt-image-2','customPrompt'=>'Keep unchanged']],
 [ArticleImageEnhancementJob::class,['targetWidth'=>160,'targetHeight'=>160,'reformatMethod'=>'crop']],
 [ArticleImageEnhancementJob::class,['targetWidth'=>160,'targetHeight'=>160,'reformatMethod'=>'fit']],
 [ArticleImageFaceBlurJob::class,['useManualFaces'=>true,'manualFaces'=>[['x'=>200,'y'=>200,'width'=>300,'height'=>300]]]],
]as[$class,$props]){
 $s=$run($class,$props);$assert($s['status']==='complete',get_class(new $class()).' completes '.json_encode($s));
 $preview=$app->getAssets()->getAssetById($s['previewId']);$assert($preview!==null&&$preview->volumeId===$asset->volumeId,'Native preview stays on own private volume');AssetHelper::deleteGeneratedAsset($preview);
}
$s=$run(ArticleImageEnhancementJob::class,['customPrompt'=>'Keep unchanged'],'http');$assert($s['status']==='failed','Provider HTTP failure becomes failed status');
$s=$run(ArticleImageEnhancementJob::class,['customPrompt'=>'Keep unchanged'],'base64');$assert($s['status']==='failed','Malformed provider base64 becomes failed status');
foreach([ArticleImageEnhancementJob::class,ArticleImageFaceBlurJob::class,ArticleImageVideoJob::class]as$class){
 $s=$run($class,[],'success',true);$assert($s['status']==='canceled','Canceled '.basename(str_replace('\\','/',$class)).' never resumes');
}
$before=craft\elements\Asset::find()->volumeId($f['volumeId'])->status(null)->count();
$s=$run(ArticleImageEnhancementJob::class,['customPrompt'=>'Keep unchanged'],'cancel');$assert($s['status']==='canceled','Cancellation during provider call survives late result');
$assert(craft\elements\Asset::find()->volumeId($f['volumeId'])->status(null)->count()===$before,'Late canceled result leaves no preview');
foreach(['google','xai'] as $provider){
 $model=$provider==='google'?'gemini-omni-1.1-flash':'grok-imagine-video-1.5';
 $s=$run(ArticleImageVideoJob::class,['videoProvider'=>$provider,'videoModel'=>$model]);
 $assert($s['status']==='complete'&&$plugin->aiVideoGeneration->isManagedVideoPath($s['videoPath']??null),$provider.' native video job produces managed MP4 '.json_encode($s));
 $assert(file_get_contents($s['videoPath'])===file_get_contents($root.'/mock.mp4'),$provider.' native video bytes match local fixture');
 $plugin->aiVideoGeneration->deleteVideo($s['videoPath']);
}
$s=$run(ArticleImageVideoJob::class,[],'video-shape');$assert($s['status']==='failed','Malformed Google video steps becomes controlled failed status');
$s=$run(ArticleImageFaceBlurJob::class);$assert($s['status']==='complete'&&$s['faceCount']===1,'Native automatic face detection uses mock and creates preview');AssetHelper::deleteGeneratedAsset($app->getAssets()->getAssetById($s['previewId']));
$permissions=$f['permissions']['writer'];$app->getUserPermissions()->saveUserPermissions($f['users']['writer'],array_values(array_diff($permissions,[ImageEnhancer::PERMISSION_USE_AI_TOOLS])));
try{
 foreach([ArticleImageEnhancementJob::class,ArticleImageFaceBlurJob::class,ArticleImageVideoJob::class]as$class){
  $calls=is_file($root.'/provider-requests.jsonl')?count(file($root.'/provider-requests.jsonl')):0;
  $s=$run($class,$class===ArticleImageEnhancementJob::class?['customPrompt'=>'Keep unchanged']:[]);
  if(!empty($s['previewId']))AssetHelper::deleteGeneratedAsset($app->getAssets()->getAssetById($s['previewId']));
  $assert($s['status']==='failed'&&str_contains($s['message']??'','permission'),'Revoked plugin permission stops '.basename(str_replace('\\','/',$class)));
  $assert(count(file($root.'/provider-requests.jsonl'))===$calls,'Revoked job never calls provider');
 }
}finally{$app->getUserPermissions()->saveUserPermissions($f['users']['writer'],$permissions);}
$app->getUserPermissions()->saveUserPermissions($f['users']['writer'],array_values(array_diff($permissions,['saveAssets:'.$f['volumeUid']])));
try{
 foreach([ArticleImageEnhancementJob::class,ArticleImageFaceBlurJob::class,ArticleImageVideoJob::class]as$class){
  $s=$run($class,$class===ArticleImageEnhancementJob::class?['customPrompt'=>'Keep unchanged']:[]);
  if(!empty($s['previewId']))AssetHelper::deleteGeneratedAsset($app->getAssets()->getAssetById($s['previewId']));
  $assert($s['status']==='failed'&&str_contains($s['message']??'','permission'),'Revoked volume save permission stops '.basename(str_replace('\\','/',$class)));
 }
}finally{$app->getUserPermissions()->saveUserPermissions($f['users']['writer'],$permissions);}
echo "Passed $checks native queue checks\n";
