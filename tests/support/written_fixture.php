<?php
declare(strict_types=1);
function writtenFixture(int $eventId = 41, string $body = 'You are a worthless coward. I meant every word.'): array
{
    $e = ['schema_version'=>1,'document_id'=>'6c4dd05e-909c-4e93-8a11-7f72181a2df4','playthrough_id'=>'unprofiled',
        'recipient'=>['kind'=>'npc','id'=>900001,'name'=>'Lydia'],'claimed_sender'=>['kind'=>'npc','id'=>900002,'name'=>'Farkas'],
        'body'=>$body,'receipt'=>['receipt_id'=>'914c2b35-7147-44f8-8c91-ae8745b11755','operation_id'=>'b98aec36-775d-4f41-aa71-90a753cff653','status'=>'confirmed','exposure'=>'context'],
        'attachments'=>[]];
    return ['rowid'=>(string)$eventId,'type'=>'info','sess'=>'written_evidence_v1','people'=>'|Lydia|','data'=>json_encode($e, JSON_THROW_ON_ERROR),'gamets'=>'100','localts'=>'100','delivery_state'=>null];
}
