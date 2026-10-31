<?php

declare(strict_types=1);

// Destructive fixtures are permitted ONLY in this disposable Docker database.
$site = getenv('IE_MCP_TEST_SITE');
if ($site !== '/tmp/ie-mcp-20261031/site') throw new RuntimeException('Wrong isolated test site.');
require $site . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
if ($app->getDb()->createCommand('SELECT DATABASE()')->queryScalar() !== 'ie_mcp_20261031') throw new RuntimeException('Wrong isolated test database.');

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\mcp\ImageTools;
use arjanbrinkman\craftimageenhancer\mcp\Access;
use arjanbrinkman\craftimageenhancer\migrations\m261031_120000_add_mcp_requests as Requests;
use arjanbrinkman\craftimageenhancer\services\EnhancementDrafts;
use arifje\mcptools\Mcp;
use arifje\mcptools\http\{Token, Scope, RecordStore};
use arifje\mcptools\enums\Capability;
use arifje\mcptools\support\CallContext;
use craft\elements\{Asset, Entry, User};
use craft\fields\{Assets, Matrix, PlainText};
use craft\models\{Volume, EntryType, Section, Section_SiteSettings, FieldLayout, FieldLayoutTab};
use craft\fieldlayoutelements\{CustomField, entries\EntryTitleField};
use craft\db\Query;
use craft\helpers\Json;
use yii\base\Event;

$checks = 0;
$check = static function(bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++; echo "PASS $label\n";
};
$reject = static function(callable $call, string $label) use ($check): void {
    try { $call(); } catch (\arjanbrinkman\craftimageenhancer\exceptions\EnhancementException|\Mcp\Exception\ToolCallException $e) { $check(true, $label); return; }
    throw new RuntimeException('Expected refusal: ' . $label);
};
$save = static function($model) use ($app): void {
    if (!$app->getElements()->saveElement($model)) throw new RuntimeException(Json::encode($model->getErrors()));
};
$plugin = ImageEnhancer::getInstance();
$check((new ReflectionClass($plugin))->getFileName() === '/tmp/ie-mcp-20261031/plugin/src/ImageEnhancer.php', 'Exact plugin source loaded');
$app->edition = craft\enums\CmsEdition::Pro;
$admin = User::find()->admin()->one(); $app->getUser()->setIdentity($admin);
$suffix = bin2hex(random_bytes(4));
$root = '/tmp/ie-mcp-20261031';
$fs = new craft\fs\Local(['name'=>'Audit '.$suffix,'handle'=>'audit'.$suffix,'path'=>"$root/assets-$suffix",'hasUrls'=>true,'url'=>'http://localhost:8099/assets/']);
if (!$app->getFs()->saveFilesystem($fs)) throw new RuntimeException(Json::encode($fs->getErrors()));
$volume = new Volume(['name'=>'Audit '.$suffix,'handle'=>'audit'.$suffix,'fsHandle'=>$fs->handle]);
if (!$app->getVolumes()->saveVolume($volume)) throw new RuntimeException(Json::encode($volume->getErrors()));
$folder = $app->getAssets()->getRootFolderByVolumeId($volume->id);
$im = new Imagick(); $im->newImage(1600,900,new ImagickPixel('#6584aa')); $im->setImageFormat('png');
$draw = new ImagickDraw(); $draw->setFillColor('red'); $draw->circle(800,450,900,450); $im->drawImage($draw); $im->writeImage("$root/source-$suffix.png"); $im->clear();
$source = new Asset(['tempFilePath'=>"$root/source-$suffix.png",'filename'=>"source-$suffix.png",'newFolderId'=>$folder->id,'volumeId'=>$volume->id,'title'=>'Test photograph','alt'=>'A red circle on blue']);
$source->setScenario(Asset::SCENARIO_CREATE);
ImageEnhancer::suppressAssetQueue(static fn()=>$save($source));
$sourceFile=$fs->path.'/'.$source->getPath(); $originalHash=hash_file('sha256',$sourceFile);
$field = new Assets(['name'=>'Thumbnail '.$suffix,'handle'=>'thumbnail'.$suffix,'defaultUploadLocationSource'=>'volume:'.$volume->uid,'defaultUploadLocationSubpath'=>'drafts/{id}','allowUploads'=>true,'restrictFiles'=>true,'allowedKinds'=>['image']]);
$other = new PlainText(['name'=>'Caption '.$suffix,'handle'=>'caption'.$suffix]);
foreach ([$field,$other] as $f) if (!$app->getFields()->saveField($f)) throw new RuntimeException(Json::encode($f->getErrors()));
$layout = static function(array $fields): FieldLayout {
    $layout=new FieldLayout(['type'=>Entry::class]); $tab=new FieldLayoutTab(['name'=>'Content']);$tab->setLayout($layout);
    $tab->setElements([new EntryTitleField(), ...array_map(static fn($f)=>new CustomField($f),$fields)]); $layout->setTabs([$tab]); return $layout;
};
$blockType=new EntryType(['name'=>'Block '.$suffix,'handle'=>'block'.$suffix]);$blockType->setFieldLayout($layout([$field]));
if (!$app->getEntries()->saveEntryType($blockType)) throw new RuntimeException(Json::encode($blockType->getErrors()));
$matrix=new Matrix(['name'=>'Blocks '.$suffix,'handle'=>'blocks'.$suffix]);$matrix->setEntryTypes([$blockType]);
if (!$app->getFields()->saveField($matrix)) throw new RuntimeException(Json::encode($matrix->getErrors()));
$type=new EntryType(['name'=>'Article '.$suffix,'handle'=>'article'.$suffix]);$type->setFieldLayout($layout([$field,$other,$matrix]));
if (!$app->getEntries()->saveEntryType($type)) throw new RuntimeException(Json::encode($type->getErrors()));
$section=new Section(['name'=>'Articles '.$suffix,'handle'=>'articles'.$suffix,'type'=>Section::TYPE_CHANNEL]);
$siteId=(int)$app->getSites()->getPrimarySite()->id;
$section->setSiteSettings([new Section_SiteSettings(['siteId'=>$siteId,'hasUrls'=>false])]);$section->setEntryTypes([$type]);
if (!$app->getEntries()->saveSection($section)) throw new RuntimeException(Json::encode($section->getErrors()));
$app->getProjectConfig()->flush();
$canonical=new Entry(['sectionId'=>$section->id,'typeId'=>$type->id,'title'=>'MCP isolated acceptance','enabled'=>false,'authorIds'=>[$admin->id]]);
$canonical->setFieldValue($field->handle,[$source->id]); $canonical->setFieldValue($other->handle,'Original caption');$save($canonical);
$newDraft=static fn()=> $app->getDrafts()->createDraft($canonical,(int)$admin->id,'MCP acceptance '.$suffix);
$draft=$newDraft();
$token=(new RecordStore())->insert(new Token('MCP audit '.$suffix,(int)$admin->id,Scope::Content,capabilities:[Capability::Upload]),hash('sha256',random_bytes(32)));
CallContext::forHttp($token,'127.0.0.1');
$tools=new ImageTools();
foreach (['image_enhancement_options','image_enhance','image_enhancement_status'] as $name) $check(Mcp::getToolRegistry()->getDefinition($name)!==null,'Tool registered: '.$name);
$schema=(new \Mcp\Capability\Discovery\SchemaGenerator(new \Mcp\Capability\Discovery\DocBlockParser()))->generate(new ReflectionMethod(ImageTools::class,'enhance'));
$check($schema['required']===['sourceAssetId','idempotencyKey']&&$schema['properties']['operation']['default']==='preserve_scene','SDK generates required arguments and preservation default');
$check($schema['properties']['operation']['enum']===['preserve_scene','crop','pad','generative_expand'],'SDK advertises explicit operation policies');
$check(!str_contains(Json::encode($tools->options()),'ApiKey'),'Discovery contains no credentials');
$call=static function(array $options=[])use($tools,$source,$suffix):array{return $tools->enhance(...array_merge(['sourceAssetId'=>(int)$source->id,'idempotencyKey'=>'audit-'.$suffix.'-'.bin2hex(random_bytes(4)),'operation'=>'crop','width'=>1200,'height'=>675],$options));};
$process=static function(array $job)use($app,$tools):array{ $app->getQueue()->executeJob((string)$job['jobId']); return $tools->status($job['requestId']); };
$relations=static function(int $id)use($field,$siteId):array{return array_map('intval',Entry::find()->id($id)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->one()->getFieldValue($field->handle)->ids());};
$req=['targetDraftId'=>(int)$draft->id,'field'=>$field->handle,'siteId'=>$siteId,'idempotencyKey'=>'audit-'.$suffix.'-acceptance'];
$job=$call($req);$again=$call($req);
$check($job['requestId']===$again['requestId']&&$job['jobId']===$again['jobId'],'Identical key returns same queued request');
$count=(new Query())->from(Requests::TABLE)->count();$queueCount=(new Query())->from(craft\db\Table::QUEUE)->count();
$tools->status($job['requestId']);$tools->status($job['requestId']);
$check((new Query())->from(Requests::TABLE)->count()===$count&&(new Query())->from(craft\db\Table::QUEUE)->count()===$queueCount,'Polling is read-only');
$done=$process($job);$check($done['stage']==='complete','Native queue completed: '.Json::encode($done));
$result=$app->getAssets()->getAssetById($done['result']['assetId']);
$check($result->id!==$source->id&&$result->width===1200&&$result->height===675,'New asset has exact 1200x675 pixels');
$check(hash_file('sha256',$sourceFile)===$originalHash,'Source bytes unchanged');
$check($relations((int)$draft->id)===[(int)$source->id,(int)$result->id],'Result appended only to requested draft');
$check($relations((int)$canonical->id)===[(int)$source->id]&&!Entry::find()->id($canonical->id)->status(null)->one()->enabled,'Canonical unchanged and unpublished');
$check($result->getFolder()->path==='drafts/'.$draft->id.'/','Native dynamic upload destination used');
$check($result->alt===$source->alt&&$done['provenance']['operation']==='crop','Technical provenance stays out of alt text');
$check($call($req)['result']['assetId']===$result->id,'Completed retry returns same result');
$reject(static fn()=>$call($req+['instructions'=>'different']), 'Same key with different arguments refused');
$reject(static fn()=>$call(['targetDraftId'=>(int)$canonical->id,'field'=>$field->handle]),'Canonical destination refused');
$reject(static fn()=>$call(['targetDraftId'=>(int)$draft->id,'field'=>$other->handle]),'Non-Assets destination refused');
$reject(static fn()=>$call(['sourceAssetId'=>999999999]),'Missing source refused');
$reject(static fn()=>$call(['provider'=>'made-up']), 'Local operations refuse provider overrides');
$reject(static fn()=>$call(['operation'=>'preserve_scene','provider'=>'made-up']), 'Unknown AI provider refused');
$reject(static fn()=>$call(['width'=>1200,'height'=>674,'aspectRatio'=>'16:9']), 'Inexact target dimensions refused');
$replacement=$newDraft();$r=$process($call(['targetDraftId'=>(int)$replacement->id,'field'=>$field->handle,'replaceAssetId'=>(int)$source->id]));
$check($relations((int)$replacement->id)===[$r['result']['assetId']],'Explicit replacement changes only selected relation');
$conflict=$newDraft();$c=$call(['targetDraftId'=>(int)$conflict->id,'field'=>$field->handle]);
$conflict->setFieldValue($field->handle,[$result->id]);$conflict->setFieldValue($other->handle,'Human edit');$save($conflict);
$c=$process($c);$check($c['stage']==='attachment_conflict'&&$c['generationSucceeded']&&!$c['attachmentSucceeded'],'Human relation change retains result with conflict');
$check($relations((int)$conflict->id)===[(int)$result->id],'Human relation is not overwritten');
$unchanged=$newDraft();$u=$call(['targetDraftId'=>(int)$unchanged->id,'field'=>$field->handle]);$unchanged->setFieldValue($other->handle,'Human unrelated edit');$save($unchanged);$u=$process($u);
$fresh=Entry::find()->id($unchanged->id)->status(null)->drafts(null)->one();
$check($u['attachmentSucceeded']&&$fresh->getFieldValue($other->handle)==='Human unrelated edit','Unrelated edits survive attachment');
// A nested entry must have an explicit, exclusive unpublished draft owner.
$nestedDraft=$newDraft();$nested=new Entry(['typeId'=>$blockType->id,'fieldId'=>$matrix->id,'title'=>'Nested test','siteId'=>$siteId]);$nested->setPrimaryOwner($nestedDraft);$nested->setOwner($nestedDraft);$nested->setFieldValue($field->handle,[$source->id]);$save($nested);
$reject(static fn()=>$call(['targetDraftId'=>(int)$nested->id,'field'=>$field->handle]),'Nested entry needs explicit root owner');
$reject(static fn()=>$call(['targetDraftId'=>(int)$nested->id,'field'=>$field->handle,'ownerDraftId'=>(int)$draft->id]),'Wrong nested root refused');
$n=$process($call(['targetDraftId'=>(int)$nested->id,'field'=>$field->handle,'ownerDraftId'=>(int)$nestedDraft->id]));
$check($n['attachmentSucceeded'],'Exclusive draft-owned nested entry supported');
$app->getDb()->createCommand()->insert(craft\db\Table::ELEMENTS_OWNERS,['elementId'=>$nested->id,'ownerId'=>$canonical->id,'sortOrder'=>1])->execute();
$reject(static fn()=>$call(['targetDraftId'=>(int)$nested->id,'field'=>$field->handle,'ownerDraftId'=>(int)$nestedDraft->id]),'Shared nested element cannot mutate canonical content');
$app->getDb()->createCommand()->delete(craft\db\Table::ELEMENTS_OWNERS,['elementId'=>$nested->id,'ownerId'=>$canonical->id])->execute();
file_put_contents("$root/unsupported-$suffix.txt",'not an image');
$textAsset=new Asset(['tempFilePath'=>"$root/unsupported-$suffix.txt",'filename'=>"unsupported-$suffix.txt",'newFolderId'=>$folder->id,'volumeId'=>$volume->id]);$textAsset->setScenario(Asset::SCENARIO_CREATE);ImageEnhancer::suppressAssetQueue(static fn()=>$save($textAsset));
$reject(static fn()=>$call(['sourceAssetId'=>(int)$textAsset->id]),'Unsupported source MIME type refused');
$failQueue=static function():void{throw new RuntimeException('Injected queue failure');};
$app->getQueue()->on(yii\queue\Queue::EVENT_BEFORE_PUSH,$failQueue);$qf=$call(['idempotencyKey'=>'audit-'.$suffix.'-queue-failure']);$app->getQueue()->off(yii\queue\Queue::EVENT_BEFORE_PUSH,$failQueue);
$check($qf['stage']==='failed'&&$qf['jobId']===null&&!$qf['generationSucceeded'],'Queue failure reports no generated result');
$check($call(['idempotencyKey'=>'audit-'.$suffix.'-queue-failure'])['requestId']===$qf['requestId'],'Queue failure retry does not duplicate requests');
// Exercise real provider serialization and decoding with a network-free Guzzle transport.
$providerCalls=0;$providerMode='success';$sentPrompts=[];
Craft::$container->set(GuzzleHttp\Client::class, static function($container, $params) use (&$providerCalls,&$providerMode,&$sentPrompts,$sourceFile) {
    $config=$params[0]??[];
    $config['handler']=static function($request,$options)use(&$providerCalls,&$providerMode,&$sentPrompts,$sourceFile){
        if ($request->getMethod()==='GET') return GuzzleHttp\Promise\Create::promiseFor(new GuzzleHttp\Psr7\Response(200,[],Json::encode(['data'=>[['id'=>'gpt-image-2']]])));
        $providerCalls++; $sentPrompts[]=(string)$request->getBody();
        $hook=$GLOBALS['ieMcpProviderHook']??null;if(is_callable($hook))$hook();
        // Captured by reference; later cases change the mode after the transport is constructed.
        // @phpstan-ignore identical.alwaysFalse
        if ($providerMode==='failure') throw new RuntimeException('PRIVATE_PROVIDER_SECRET diagnostic');
        return GuzzleHttp\Promise\Create::promiseFor(new GuzzleHttp\Psr7\Response(200,[],Json::encode(['data'=>[['b64_json'=>base64_encode(file_get_contents($sourceFile))]]])));
    };
    return new GuzzleHttp\Client($config);
});
$settings=$plugin->getSettings();$settings->chatGptApiKey='TEST_KEY_NEVER_SEND';$settings->imageEnhancementProvider='openai';$settings->imageEnhancementModel='gpt-image-2';
$check(!str_contains(Json::encode($tools->options()),'TEST_KEY_NEVER_SEND'),'Configured key is absent from discovery');
$reject(static fn()=>$call(['operation'=>'preserve_scene','provider'=>'openai','model'=>'unsupported-model']),'Unsupported model refused before provider call');
$aiDraft=$newDraft();$aiReq=['operation'=>'preserve_scene','provider'=>'openai','model'=>'gpt-image-2','targetDraftId'=>(int)$aiDraft->id,'field'=>$field->handle,'instructions'=>'Correct exposure only.','idempotencyKey'=>'audit-'.$suffix.'-ai'];
$ai=$process($call($aiReq));
$check($ai['stage']==='complete'&&$ai['result']['width']===1200&&$ai['result']['height']===675&&$ai['attachmentSucceeded'],'Mock AI passes through real provider service into exact draft result');
$check($providerCalls===1&&str_contains($sentPrompts[0],'untrusted')&&str_contains($sentPrompts[0],'Preserve the scene'),'Preservation and untrusted-text constraints sent to provider');
$aiAgain=$call($aiReq);$plugin->mcpEnhancements->process($ai['requestId']);$tools->status($ai['requestId']);
$check($providerCalls===1&&$aiAgain['result']['assetId']===$ai['result']['assetId'],'Retries and polling do not duplicate paid work');
$pad=$process($call(['operation'=>'pad','width'=>900,'height'=>900]));$check($pad['stage']==='complete'&&$providerCalls===1,'Local padding never invokes AI');
$expanded=$process($call(['operation'=>'generative_expand','provider'=>'openai','model'=>'gpt-image-2','width'=>1200,'height'=>900]));
$check($expanded['stage']==='complete'&&$expanded['provenance']['generativeExpansion']&&count($expanded['warnings'])===2,'Explicit expansion records generated-content provenance and warnings');
$blockSave=static function($event):void{ if ($event->element instanceof Asset && $event->isNew) throw new RuntimeException('Injected asset storage failure'); };
Event::on(craft\services\Elements::class,craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT,$blockSave);
$storageReq=['operation'=>'preserve_scene','provider'=>'openai','model'=>'gpt-image-2','idempotencyKey'=>'audit-'.$suffix.'-storage'];
$storage=$process($call($storageReq));Event::off(craft\services\Elements::class,craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT,$blockSave);
$check($storage['stage']==='storage_failed'&&$storage['generationSucceeded']&&!$storage['storageSucceeded'],'Storage failure retains completed generation');
$paidBefore=$providerCalls;$retried=$process($call($storageReq));
$check($retried['stage']==='complete'&&$providerCalls===$paidBefore,'Same-key storage retry reuses pixels without another charge');
$attachDraft=$newDraft();$attachRequest=$call(['targetDraftId'=>(int)$attachDraft->id,'field'=>$field->handle]);
$blockAttach=static function($event):void{$event->isValid=false;};
Event::on(Entry::class,Entry::EVENT_BEFORE_SAVE,$blockAttach);$af=$process($attachRequest);Event::off(Entry::class,Entry::EVENT_BEFORE_SAVE,$blockAttach);
$check($af['stage']==='attachment_failed'&&$af['generationSucceeded']&&$af['result']!==null,'Attachment validation failure retains completed asset');
$unknown=$call(['operation'=>'preserve_scene','provider'=>'openai','model'=>'gpt-image-2']);
$app->getDb()->createCommand()->update(Requests::TABLE,['status'=>'processing'],['requestId'=>$unknown['requestId']])->execute();$paidBefore=$providerCalls;$unknown=$process($unknown);
$check($unknown['stage']==='uncertain'&&$providerCalls===$paidBefore,'Interrupted provider outcome never triggers automatic paid retry');
$providerMode='failure';$pf=$process($call(['operation'=>'preserve_scene','provider'=>'openai','model'=>'gpt-image-2']));
$check($pf['stage']==='failed'&&!str_contains(Json::encode($pf),'PRIVATE_PROVIDER_SECRET'),'Provider failure is actionable and secrets are redacted');$providerMode='success';
$scoped=(new RecordStore())->insert(new Token('Other section',(int)$admin->id,Scope::Content,sections:['wrong-section'],capabilities:[Capability::Upload]),hash('sha256',random_bytes(32)));
CallContext::forHttp($scoped,'127.0.0.1');$reject(static fn()=>$call(['targetDraftId'=>(int)$draft->id,'field'=>$field->handle]),'Token section scope rejects destination');
$reject(static fn()=>$tools->status($ai['requestId']),'Status respects section scope');CallContext::forHttp($token,'127.0.0.1');

// Scoped tokens and native permissions are both enforced, including full scope.
$noUpload=new Token('No upload',(int)$admin->id,Scope::Content,id:$token->id,capabilities:[]);CallContext::forHttp($noUpload,'127.0.0.1');
$reject(static fn()=>$call(),'Missing upload capability refused');
CallContext::forHttp(new Token('Read only',(int)$admin->id,Scope::ReadOnly,id:$token->id),'127.0.0.1');$reject(static fn()=>$call(),'Read-only mutation refused');
CallContext::reset();$reject(static fn()=>$tools->options(),'Implicit console identity refused');CallContext::forHttp($token,'127.0.0.1');
$user=new User(['username'=>'denied'.$suffix,'email'=>'denied'.$suffix.'@example.test','active'=>true,'pending'=>false]);$save($user);$app->getUser()->setIdentity($user);
$reject(static fn()=>Access::assertUser($user),'Native user without CP/plugin permissions refused');
$perms=['accessCp',ImageEnhancer::PERMISSION_USE_AI_TOOLS];
$app->getUserPermissions()->saveUserPermissions((int)$user->id,$perms);
$userToken=(new RecordStore())->insert(new Token('Limited user',(int)$user->id,Scope::Full),hash('sha256',random_bytes(32)));CallContext::forHttp($userToken,'127.0.0.1');
$reject(static fn()=>$call(),'Full MCP scope cannot bypass source asset permission');
$perms[]='viewAssets:'.$volume->uid;$perms[]='viewPeerAssets:'.$volume->uid;
$app->getUserPermissions()->saveUserPermissions((int)$user->id,$perms);
$reject(static fn()=>$call(),'Source view permission does not grant destination upload');
$perms[]='saveAssets:'.$volume->uid;$app->getUserPermissions()->saveUserPermissions((int)$user->id,$perms);
$reject(static fn()=>$call(['targetDraftId'=>(int)$draft->id,'field'=>$field->handle]),'Asset upload permission does not grant draft editing');
$limited=$process($call());$check($limited['stage']==='complete','Permitted non-admin can create a separate asset');
$reject(static fn()=>$tools->status($ai['requestId']),'Another user cannot read request results');
$lateReq=['operation'=>'preserve_scene','provider'=>'openai','model'=>'gpt-image-2','idempotencyKey'=>'audit-'.$suffix.'-late-revocation'];$late=$call($lateReq);$paidBefore=$providerCalls;
$GLOBALS['ieMcpProviderHook']=static function()use($app,$user):void{
    $permissionId=(new Query())->select('id')->from(craft\db\Table::USERPERMISSIONS)->where(['name'=>strtolower(ImageEnhancer::PERMISSION_USE_AI_TOOLS)])->scalar();
    $app->getDb()->createCommand()->delete(craft\db\Table::USERPERMISSIONS_USERS,['userId'=>$user->id,'permissionId'=>$permissionId])->execute();
};
$app->getQueue()->executeJob((string)$late['jobId']);unset($GLOBALS['ieMcpProviderHook']);
$lateRow=(new Query())->from(Requests::TABLE)->where(['requestId'=>$late['requestId']])->one();
$check($lateRow['status']==='storage_failed'&&$lateRow['resultAssetId']===null,'External permission revocation during provider call stops storage despite cached permissions');
$app->getUserPermissions()->saveUserPermissions((int)$user->id,$perms);$lateDone=$process($call($lateReq));
$check($lateDone['stage']==='complete'&&$providerCalls===$paidBefore+1,'Authorized retry after permission recovery reuses generated pixels');

$app->getUser()->setIdentity($admin);CallContext::forHttp($token,'127.0.0.1');
$revoked=$call();(new RecordStore())->delete($token->id);$rv=$process($revoked);$check($rv['stage']==='failed'&&!$rv['generationSucceeded'],'Revoked token stops queued work');
file_put_contents("$root/acceptance.json",Json::encode(['craft'=>$app->version,'sourceAssetId'=>$source->id,'canonicalId'=>$canonical->id,'draftId'=>$aiDraft->id,'resultAssetId'=>$ai['result']['assetId'],'providerTransport'=>'mock Guzzle; real provider serialization and decoding','dimensions'=>[$result->width,$result->height],'field'=>$field->handle,'siteId'=>$siteId,'sourceSha256'=>$originalHash,'originalPreserved'=>hash_file('sha256',$sourceFile)===$originalHash,'draftUnpublished'=>true,'checks'=>$checks]));
echo "Passed $checks native MCP checks on Craft {$app->version}.\n";
