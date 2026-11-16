<?php

declare(strict_types=1);

// Never run this fixture against an editorial database or shared storage.
$database = getenv('CRAFT_DB_DATABASE');
if ($database !== 'ai_assistant_review_candidates') throw new RuntimeException('Use the disposable candidates database.');
require $argv[1];
spl_autoload_register(static function(string $class): void {
    foreach (['arjanbrinkman\\craftimageenhancer\\' => dirname(__DIR__) . '/src/', 'arifje\\craftaiassistant\\' => '/workspace/Codex/craft-plugins/craft-ai-assistent/src/'] as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
        }
    }
}, true, true);
define('CRAFT_BASE_PATH', dirname($argv[1], 2));
define('CRAFT_VENDOR_PATH', dirname($argv[1]));
define('CRAFT_STORAGE_PATH', '/tmp/ai_assistant_review_candidates-storage');
Dotenv\Dotenv::createUnsafeImmutable(CRAFT_BASE_PATH)->safeLoad();
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
if ($app->getDb()->createCommand('SELECT DATABASE()')->queryScalar() !== $database) throw new RuntimeException('Wrong database.');
if (($argv[2] ?? '') === '--lock') {
    if (!$app->getMutex()->acquire($argv[3], 1)) throw new RuntimeException('Child lock failed.');
    echo "locked\n"; fflush(STDOUT); fgets(STDIN);
    $app->getMutex()->release($argv[3]); exit;
}

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\mcp\ImageTools;
use arjanbrinkman\craftimageenhancer\migrations\m261031_120000_add_mcp_requests as Requests;
use arifje\mcptools\http\Scope;
use arifje\mcptools\support\CallContext;
use craft\elements\{Asset, User};
use craft\models\Volume;
use craft\db\Query;
use craft\helpers\Json;
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
$check((new ReflectionClass($plugin))->getFileName() === dirname(__DIR__) . '/src/ImageEnhancer.php', 'Exact plugin source loaded');
$app->edition = craft\enums\CmsEdition::Pro;
$admin = User::find()->admin()->one(); $app->getUser()->setIdentity($admin);
$suffix = bin2hex(random_bytes(4));
$root = CRAFT_STORAGE_PATH;
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

CallContext::forLocal((int)$admin->id, Scope::Full, null);
$tools = new ImageTools();
$service = $plugin->mcpEnhancements;
$call = static fn(array $args = []) => $tools->enhance(...array_replace(['sourceAssetId' => (int)$source->id, 'idempotencyKey' => 'candidate-'.$suffix.'-'.bin2hex(random_bytes(4)), 'operation' => 'crop', 'width' => 1200, 'height' => 675, 'outputMode' => 'temporary'], $args));
$process = static function(array $job) use ($service, $tools, $app): array {
    $service->process($job['operationId']);
    $app->getQueue()->release((string)$job['queueJobId']);
    return $tools->status(operationId: $job['operationId']);
};
$count = static fn(): int => (int)Asset::find()->volumeId($volume->id)->status(null)->count();
$before = $count();
$job = $call();
$id = $job['operationId'];
$reject(static fn() => $tools->save($id), 'Cannot save unfinished work');
$state = $process($job);
$check($state['stage'] === 'awaiting_review' && $state['executionStatus'] === 'finished', 'Temporary completion is not an interrupted queue job');
$check($count() === $before && $state['result']['temporary'] && !isset($state['result']['assetId']), 'Processing creates no candidate asset');
$check($state['generationSucceeded'] && !$state['storageSucceeded'], 'Generation and asset storage remain distinct');
$path = $service->previewPath($id, $admin);
$check(is_file($path) && str_starts_with($path, CRAFT_STORAGE_PATH), 'Candidate stays in private storage');
$view = arifje\craftaiassistant\helpers\ToolImages::forModel('enhancement://'.$id);
$check(is_string($view) && str_starts_with($view, 'data:image/jpeg;base64,'), 'Assistant sees private candidate pixels');
$service->process($id);
$check($count() === $before && is_file($path), 'Worker replay does not promote or regenerate candidate');
$other = clone $admin; $other->id = -987;
$reject(static fn() => $service->previewPath($id, $other), 'Other user cannot read candidate');
$reject(static fn() => $service->settle($id, true, $other, []), 'Other user cannot promote candidate');
$reject(static fn() => $service->previewPath('../../etc/passwd', $admin), 'Preview rejects arbitrary paths');
$child = proc_open([PHP_BINARY, __FILE__, $argv[1], '--lock', 'ie-mcp-process:'.$id], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
if (!is_resource($child)) throw new RuntimeException('Could not launch concurrency fixture.');
try {
    $check(trim((string)fgets($pipes[1])) === 'locked', 'Independent process holds the native mutex');
    $reject(static fn() => $tools->save($id), 'Concurrent save refuses duplicate work');
    $reject(static fn() => $tools->discard($id), 'Concurrent discard cannot race promotion');
} finally {
    fwrite($pipes[0], "done\n"); foreach ($pipes as $pipe) fclose($pipe); proc_close($child);
}
$saved = $tools->save($id);
$check($saved['stage'] === 'complete' && $count() === $before + 1 && !is_file($path), 'Saving creates one final asset and removes temporary file');
$check($tools->save($id)['result']['assetId'] === $saved['result']['assetId'] && $count() === $before + 1, 'Duplicate save returns same asset');
$reject(static fn() => $tools->discard($id), 'Discard cannot delete a saved asset');
$check($count() === $before + 1 && hash_file('sha256', $sourceFile) === $originalHash, 'Original file and chosen asset remain intact');
$rejected = $process($call()); $rid = $rejected['operationId']; $rpath = $service->previewPath($rid,$admin);
$check($tools->discard($rid)['stage'] === 'discarded' && !is_file($rpath) && $count() === $before+1, 'Rejecting deletes only private candidate');
$check($tools->discard($rid)['stage'] === 'discarded', 'Discard is idempotent');
$reject(static fn() => $tools->save($rid), 'Discarded result cannot be promoted');
$reject(static fn() => $service->previewPath($rid,$admin), 'Discarded result cannot be previewed');
$legacy = $process($call(['outputMode'=>'asset']));
$check($legacy['stage'] === 'complete' && $legacy['result']['assetId'] > 0 && $count() === $before+2, 'Legacy immediate asset mode remains supported');
$expired = $process($call()); $eid = $expired['operationId']; $epath = $service->previewPath($eid,$admin);
$app->getDb()->createCommand()->update(Requests::TABLE, ['dateUpdated' => '2000-01-01 00:00:00'], ['requestId'=>$eid])->execute();
$service->purgeCompletedDetails();
$check(!is_file($epath) && $tools->status(operationId:$eid)['detailsExpired'], 'Craft GC removes abandoned private pixels');
$reject(static fn() => $tools->save($eid), 'Expired candidate cannot be regenerated or saved');

$assistant = arifje\craftaiassistant\Plugin::getInstance();
$assistant->getSettings()->uploadVolume = $volume->handle;
$assistant->getSettings()->uploadFolder = '';
$uploads = $assistant->getUploads();
$upload = $uploads->store($admin, new craft\web\UploadedFile(['name'=>'private.png', 'tempName'=>$sourceFile, 'size'=>filesize($sourceFile), 'type'=>'image/png', 'error'=>0]));
$before = $count();
$private = $process($call(['sourceAssetId'=>null, 'sourceUploadId'=>$upload['id']]));
$check($count() === $before && $uploads->find($admin,$upload['id'])['assetId'] === null, 'Private upload enhancement creates no original or candidate asset');
$parent = $private['operationId'];
$child = $process($call(['sourceAssetId'=>null, 'sourceOperationId'=>$parent, 'width'=>800, 'height'=>450]));
$check($count() === $before && $child['result']['width'] === 800, 'Chained crop stays temporary');
$tools->discard($parent);
$check(is_file($service->previewPath($child['operationId'],$admin)), 'Finished child remains readable after parent discard');
// Original uploads expire independently of durable operation outcomes.
$meta = $uploads->find($admin,$upload['id']); unlink($meta['path']);
$final = $tools->save($child['operationId']);
$check($count() === $before+1 && $final['result']['width'] === 800, 'Only final asset is saved, even after original upload expires');
$reject(static fn() => $call(['sourceAssetId'=>null,'sourceUploadId'=>'not-an-upload']), 'Unknown private upload refused');
$reject(static fn() => $call(['sourceUploadId'=>$upload['id']]), 'Ambiguous sources refused');
$missing = $process($call()); $mid=$missing['operationId']; $mpath=$service->previewPath($mid,$admin);
unlink($mpath);
$reject(static fn() => $tools->save($mid), 'Missing retained pixels do not trigger generation');
// Exercise the actual assistant hold/approve/decline path with no provider calls.
$model = new class implements arifje\craftaiassistant\providers\TextModel {
    public ?array $call = null;
    public function respond(string $instructions, array $input, array $tools, ?callable $stopped = null): array {
        $call=$this->call; $this->call=null;
        return ['text'=>$call===null?'Done.':'', 'calls'=>$call===null?[]:[$call], 'items'=>[]];
    }
    public function userItem(string $text,array $images=[]):array {return ['role'=>'user','content'=>$text];}
    public function toolResultItem(string $callId,string $output):array {return ['type'=>'function_call_output','call_id'=>$callId,'output'=>$output];}
};
$assistant->getAgent()->setModel($model);
$assistant->getPreferences()->setReviewMode($admin,true);
foreach ([true,false] as $accept) {
    CallContext::forLocal((int)$admin->id, Scope::Full, null);
    $candidate=$process($call()); $cid=$candidate['operationId'];
    $before=$count();
    $conversation=$assistant->getConversations()->create($admin);
    $model->call=['id'=>'save-candidate','name'=>'image_enhancement_save','arguments'=>['operationId'=>$cid]];
    $answer=$assistant->getAgent()->turn($admin,$conversation,'Use this candidate.');
    $pending=$assistant->getConversations()->pendingConfirmation($conversation);
    $check($pending!==null && $pending['tool']==='image_enhancement_save' && $pending['review']['kind']==='images' && $count()===$before, 'Review card holds private image before saving');
    $assistant->getAgent()->turn($admin,$conversation,$accept?'Looks good.':'Discard it.', ['confirm'=>['token'=>$pending['token'],'yes'=>$accept]]);
    CallContext::forLocal((int)$admin->id, Scope::Full, null);
    $check($tools->status(operationId:$cid)['stage']===($accept?'complete':'discarded') && $count()===$before+($accept?1:0), $accept?'Approval promotes exactly once':'Rejection discards without creating or deleting assets');
}
$assistant->getPreferences()->setReviewMode($admin,false);

CallContext::forLocal((int)$admin->id, Scope::Full, null);
$recover=$process($call()); $recoverId=$recover['operationId']; $before=$count();
$row=(new Query())->from(Requests::TABLE)->where(['requestId'=>$recoverId])->one();
$payload=Json::decode($row['request']); $broken=$payload; $broken['folderId']=-999;
$app->getDb()->createCommand()->update(Requests::TABLE,['request'=>Json::encode($broken)],['requestId'=>$recoverId])->execute();
$reject(static fn() => $tools->save($recoverId), 'Unavailable destination leaves candidate unsaved');
$check($count()===$before && is_file($service->previewPath($recoverId,$admin)), 'Failed save retains reviewed pixels');
$app->getDb()->createCommand()->update(Requests::TABLE,['request'=>Json::encode($payload)],['requestId'=>$recoverId])->execute();
$check($tools->save($recoverId)['stage']==='complete' && $count()===$before+1, 'Corrected save reuses pixels without processing again');
$conversation=$assistant->getConversations()->create($admin);
$before=$count();
$model->call=['id'=>'make-candidate','name'=>'image_enhance','arguments'=>['sourceAssetId'=>(int)$source->id,'idempotencyKey'=>'explicit-asset-test','operation'=>'crop','width'=>640,'height'=>360,'outputMode'=>'asset']];
$assistant->getAgent()->turn($admin,$conversation,'Improve this image.');
$row=(new Query())->from(Requests::TABLE)->orderBy(['id'=>SORT_DESC])->one();
$check(Json::decode($row['request'])['outputMode']==='temporary', 'Assistant feature-detects and enforces temporary output');
CallContext::forLocal((int)$admin->id, Scope::Full, null);
$ready=$process(['operationId'=>$row['requestId'],'queueJobId'=>$row['queueJobId']]);
$check($assistant->getJobs()->state($ready)==='done' && $count()===$before, 'Background candidate completion resumes workflow without an asset');
$schema=(new \Mcp\Capability\Discovery\SchemaGenerator(new \Mcp\Capability\Discovery\DocBlockParser()))->generate(new ReflectionMethod(ImageTools::class,'enhance'));
$check($schema['properties']['outputMode']['enum'] === ['asset','temporary'] && $schema['properties']['outputMode']['default'] === 'asset', 'Schema advertises backwards-compatible mode');
echo "Passed $checks private candidate integration checks.\n";
