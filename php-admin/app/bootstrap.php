<?php
declare(strict_types=1);
function envv(string $key,string $default=''): string { $v=getenv($key); return ($v===false||$v==='')?$default:$v; }
define('APP_URL',rtrim(envv('APP_URL',''),'/'));
define('DB_PATH',envv('DB_PATH',__DIR__.'/../storage/jattbeat.sqlite'));
if(!is_dir(dirname(DB_PATH))) @mkdir(dirname(DB_PATH),0775,true);
session_name(envv('SESSION_NAME','jattbeat_admin'));
session_set_cookie_params(['httponly'=>true,'secure'=>(envv('APP_ENV')==='production'||(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')),'samesite'=>'Lax']);
if(session_status()!==PHP_SESSION_ACTIVE) session_start();
function db(): PDO {
    static $p=null;
    if($p) return $p;
    $p=new PDO('sqlite:'.DB_PATH,null,null,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
    $p->exec('PRAGMA foreign_keys=ON');

    // Initialize missing tables safely. CREATE TABLE IF NOT EXISTS preserves
    // all existing catalogue data and repairs fresh/partially initialized DBs.
    $schemaFile=__DIR__.'/schema.sql';
    if(!is_file($schemaFile)) throw new RuntimeException('Database schema file is missing: '.$schemaFile);
    $schema=(string)file_get_contents($schemaFile);
    if(trim($schema)==='') throw new RuntimeException('Database schema file is empty.');
    $p->exec($schema);

    // Add newer optional catalogue fields to older databases without deleting data.
    $columns=$p->query('PRAGMA table_info(songs)')->fetchAll();
    $names=array_column($columns,'name');
    foreach(['source_url'=>'TEXT DEFAULT \'\'','duration'=>'TEXT DEFAULT \'\'','label'=>'TEXT DEFAULT \'\''] as $name=>$definition) {
        if(!in_array($name,$names,true)) $p->exec('ALTER TABLE songs ADD COLUMN '.$name.' '.$definition);
    }
    $p->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_songs_source_url ON songs(source_url) WHERE source_url IS NOT NULL AND source_url <> ''");
    $p->exec("INSERT OR IGNORE INTO settings(key,value) VALUES('site_name','JattBeat')");
    $p->exec("INSERT OR IGNORE INTO settings(key,value) VALUES('site_description','Discover Punjabi songs, singles and artist releases on JattBeat.')");
    return $p;
}
function e(?string $v):string{return htmlspecialchars($v??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function check_csrf():void{if(!hash_equals($_SESSION['csrf']??'',$_POST['_csrf']??'')){http_response_code(419);exit('Invalid CSRF token');}}
function admin_required():void{if(empty($_SESSION['admin_id'])){header('Location: /login.php');exit;}}
function slugify(string $s):string{$s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s)?:$s;$s=strtolower(trim($s));$s=preg_replace('/[^a-z0-9]+/','-',$s);return trim($s,'-')?:'track';}
function setting(string $k,string $d=''):string{$q=db()->prepare('SELECT value FROM settings WHERE key=?');$q->execute([$k]);$v=$q->fetchColumn();return $v===false?$d:(string)$v;}
function save_setting(string $k,string $v):void{$q=db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');$q->execute([$k,$v]);}

function importer_post(string $path,array $payload): array {
    $base=rtrim(envv('IMPORT_API_URL','https://dj-laqb.onrender.com'),'/');
    $token=envv('IMPORT_TOKEN','');
    if($token==='') throw new RuntimeException('Importer is not configured. Add IMPORT_TOKEN to the PHP Render service environment.');
    $url=$base.$path;
    $context=stream_context_create(['http'=>[
        'method'=>'POST',
        'header'=>"Content-Type: application/json\r\nAccept: application/json\r\nX-Import-Token: ".$token."\r\n",
        'content'=>json_encode($payload,JSON_UNESCAPED_SLASHES),
        'timeout'=>180,
        'ignore_errors'=>true
    ]]);
    $body=@file_get_contents($url,false,$context);
    $headers=$http_response_header??[];
    if($body===false) {
        $last=error_get_last();
        throw new RuntimeException('Could not connect to importer API at '.$url.'. '.($last['message']??'Network request failed'));
    }
    $status=0;
    $contentType='missing';
    foreach($headers as $header) {
        if(preg_match('/^HTTP\/\S+\s+(\d+)/',$header,$m)) $status=(int)$m[1];
        if(stripos($header,'Content-Type:')===0) $contentType=trim(substr($header,13));
    }
    $data=json_decode($body,true);
    if(!is_array($data)) {
        $excerpt=trim(preg_replace('/\s+/',' ',strip_tags($body)));
        throw new RuntimeException('Importer returned non-JSON. HTTP '.$status.'; content-type='.$contentType.'; response='.substr($excerpt?:'[empty response]',0,350));
    }
    if($status<200||$status>=300) {
        $detail=$data['detail']??$data['message']??json_encode($data);
        throw new RuntimeException('Importer API HTTP '.$status.': '.substr((string)$detail,0,350));
    }
    return $data;
}

function importer_get(string $path): array {
    $base=rtrim(envv('IMPORT_API_URL','https://dj-laqb.onrender.com'),'/');
    $token=envv('IMPORT_TOKEN','');
    if($token==='') throw new RuntimeException('Importer is not configured. Add IMPORT_TOKEN to the PHP Render service environment.');
    $url=$base.$path;
    $context=stream_context_create(['http'=>[
        'method'=>'GET',
        'header'=>"Accept: application/json\r\nX-Import-Token: ".$token."\r\n",
        'timeout'=>20,
        'ignore_errors'=>true
    ]]);
    $body=@file_get_contents($url,false,$context);
    $headers=$http_response_header??[];
    if($body===false) throw new RuntimeException('Could not read importer job status.');
    $status=0;
    foreach($headers as $header) if(preg_match('/^HTTP\\/\\S+\\s+(\\d+)/',$header,$m)) $status=(int)$m[1];
    $data=json_decode($body,true);
    if(!is_array($data)) throw new RuntimeException('Importer status returned non-JSON (HTTP '.$status.').');
    if($status<200||$status>=300) throw new RuntimeException((string)($data['detail']??'Importer status request failed (HTTP '.$status.').'));
    return $data;
}
