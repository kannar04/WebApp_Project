<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$root=dirname(__DIR__,2);
$listings=json_decode(file_get_contents($root.'/demo_stays/airbnb_vietnam/listings.json'),true,512,JSON_THROW_ON_ERROR);
$folder=$root.'/public/uploads';
if(!is_dir($folder)){mkdir($folder,0775,true);}
$jobs=[];
foreach($listings as $listing){
    foreach(array_slice($listing['photos'],0,3) as $index=>$url){
        $path=$folder.'/airbnb-'.$listing['source_id'].'-'.($index+1).'.jpg';
        if(is_file($path) && @getimagesize($path)){continue;}
        $jobs[]=['url'=>$url.'?im_w=960','path'=>$path];
    }
}
$multi=curl_multi_init();$active=[];$completed=0;$failed=0;
do {
    while(count($active)<12 && $jobs){
        $job=array_pop($jobs);$handle=curl_init($job['url']);
        curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>60,CURLOPT_CONNECTTIMEOUT=>15]);
        $active[spl_object_id($handle)]=[$handle,$job];curl_multi_add_handle($multi,$handle);
    }
    do{$status=curl_multi_exec($multi,$running);}while($status===CURLM_CALL_MULTI_PERFORM);
    while($info=curl_multi_info_read($multi)){
        $handle=$info['handle'];[, $job]=$active[spl_object_id($handle)];$bytes=curl_multi_getcontent($handle);
        if($info['result']===CURLE_OK && curl_getinfo($handle,CURLINFO_HTTP_CODE)===200 && @getimagesizefromstring($bytes)){
            file_put_contents($job['path'],$bytes);$completed++;
        }else{$failed++;echo 'Photo failed: '.basename($job['path'])."\n";}
        curl_multi_remove_handle($multi,$handle);unset($active[spl_object_id($handle)]);curl_close($handle);
        if(($completed+$failed)%50===0){echo "Downloaded $completed; failed $failed; queued ".count($jobs)."\n";}
    }
    if($running){curl_multi_select($multi,1);}
}while($jobs || $active);
curl_multi_close($multi);
echo "Downloaded $completed photos; $failed failures.\n";
exit($failed?1:0);
