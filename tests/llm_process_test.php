<?php
declare(strict_types=1);
require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/llm_connector.php';
use function ChimSwornAndScorned\relationshipConnectorCompletion;

check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'process checks only in disposable clone');
$root = sys_get_temp_dir().'/sas-llm-process-'.bin2hex(random_bytes(8));
foreach (['conf','lib/core'] as $dir) mkdir($root.'/'.$dir,0700,true);
$pid = 0;
$socket = null;
$savedEngine = $GLOBALS['ENGINE_PATH'] ?? null;
$savedId = $GLOBALS['RELLLM_CONNECTOR'] ?? null;
try {
    foreach (['main.php','lib/logger.php','lib/settings.php'] as $file) file_put_contents($root.'/'.$file,'<?php');
    file_put_contents($root.'/lib/postgresql.class.php','<?php class sql {}');
    file_put_contents($root.'/lib/core/llm_connector.class.php', <<<'PHP'
<?php
class LLMConnector {
    function readOne($id) { return ['id'=>$id,'driver'=>'openrouterjson','model'=>'fixture-model']; }
    function setOldGlobals($row) { $GLOBALS['CONNECTOR']['openrouterjson']=['API_KEY'=>'dummy-child-only']; }
    function getConnector($row) { return new class {
        function fast_request($messages,$params,$name) {
            if ($GLOBALS['MODE']==='fail') throw new RuntimeException('secret-must-not-escape');
            if ($GLOBALS['MODE']==='fast') return json_encode(['actor'=>$GLOBALS['HERIKA_NAME'],'player'=>$GLOBALS['PLAYER_NAME'],'budget'=>$params['MAX_TOKENS'],'messages'=>$messages]);
            $context=stream_context_create(['http'=>['timeout'=>20]]);
            return file_get_contents($GLOBALS['URL'],false,$context);
        }
    }; }
}
PHP);
    $GLOBALS['ENGINE_PATH']=$root;
    $GLOBALS['RELLLM_CONNECTOR']=3;
    file_put_contents($root.'/conf/conf.php','<?php $GLOBALS["MODE"]="fast";');
    $complete=relationshipConnectorCompletion(1024,'Actor Fixture','Player Fixture',1);
    $reply=json_decode($complete([['role'=>'user','content'=>'dummy evidence']]),true);
    check($reply===['actor'=>'Actor Fixture','player'=>'Player Fixture','budget'=>1024,'messages'=>[['role'=>'user','content'=>'dummy evidence']]],'fresh child receives scoped names, budget and evidence');
    check($GLOBALS['ENGINE_PATH']===$root && !isset($GLOBALS['CONNECTOR']['openrouterjson']['API_KEY']),'child does not modify parent globals or return its key');
    file_put_contents($root.'/conf/conf.php','<?php $GLOBALS["MODE"]="fail";');
    $failure=null;
    try { $complete([]); } catch (RuntimeException $error) { $failure=$error->getMessage(); }
    check($failure==='connector_request_failed','child errors expose only a fixed reason');

    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
    check(is_resource($socket),'local drip fixture binds');
    $address=stream_socket_get_name($socket,false);
    $pid=pcntl_fork();
    if ($pid===0) {
        $client=stream_socket_accept($socket,5);
        fclose($socket);
        if (is_resource($client)) {
            while (($line=fgets($client))!==false && $line!=="\r\n") {}
            fwrite($client,"HTTP/1.1 200 OK\r\nContent-Length: 8\r\nConnection: close\r\n\r\n");
            fflush($client);
            foreach (str_split('abcdefgh') as $byte) { usleep(400000); if (@fwrite($client,$byte)!==1) break; fflush($client); }
            fclose($client);
        }
        exit(0);
    }
    check($pid>0,'local drip fixture forks');
    fclose($socket); $socket=null;
    file_put_contents($root.'/conf/conf.php','<?php $GLOBALS["MODE"]="slow"; $GLOBALS["URL"]='.var_export('http://'.$address.'/slow',true).';');
    $start=hrtime(true); $failure=null;
    try { $complete([]); } catch (RuntimeException $error) { $failure=$error->getMessage(); }
    $elapsed=(hrtime(true)-$start)/1e9;
    check($failure==='connector_timeout' && $elapsed<1.75,sprintf('native drip HTTP read is killed within total deadline: %s %.3fs',$failure??'none',$elapsed));
    echo json_encode(['native_stream_deadline_seconds'=>1,'observed_seconds'=>$elapsed,'failure'=>$failure]),"\n";
} finally {
    if (is_resource($socket)) fclose($socket);
    if ($pid>0) { @posix_kill($pid,SIGKILL); pcntl_waitpid($pid,$status); }
    if ($savedEngine===null) unset($GLOBALS['ENGINE_PATH']); else $GLOBALS['ENGINE_PATH']=$savedEngine;
    if ($savedId===null) unset($GLOBALS['RELLLM_CONNECTOR']); else $GLOBALS['RELLLM_CONNECTOR']=$savedId;
    $paths=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($paths as $path) $path->isDir()?rmdir($path->getPathname()):unlink($path->getPathname());
    rmdir($root);
}
echo "llm process checks passed\n";
