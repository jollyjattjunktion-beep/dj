<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
try {
    $limit=max(1,min(500,(int)($_GET['limit']??500)));
    $q=db()->prepare("SELECT s.id,s.title,s.slug,s.album,s.release_date,s.description,s.artwork_url,s.media_url,s.media_type,s.meta_title,s.meta_description,s.created_at,s.updated_at,COALESCE(a.name,'') AS artist,COALESCE(s.duration,'') AS duration,COALESCE(s.label,'') AS label FROM songs s LEFT JOIN artists a ON a.id=s.artist_id WHERE s.published=1 ORDER BY CASE WHEN s.release_date IS NOT NULL AND s.release_date<>'' THEN s.release_date ELSE s.created_at END DESC,s.id DESC LIMIT ?");
    $q->bindValue(1,$limit,PDO::PARAM_INT);
    $q->execute();
    echo json_encode(['ok'=>true,'items'=>$q->fetchAll()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch(Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'items'=>[],'error'=>'Catalogue is temporarily unavailable.'],JSON_UNESCAPED_SLASHES);
}
