<?php
require __DIR__.'/app/bootstrap.php';
admin_required();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$id=trim($_GET['id']??'');
if($id===''||!preg_match('/^[A-Za-z0-9_-]{10,80}$/',$id)){http_response_code(400);echo json_encode(['error'=>'Invalid import job ID']);exit;}
try{$data=importer_get('/admin/import-pages/'.rawurlencode($id));echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}catch(Throwable $e){http_response_code(502);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
