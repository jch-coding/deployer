<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task job queue shard count
    |--------------------------------------------------------------------------
    |
    | Tasks are assigned queue names q0 .. q(N-1). A larger N reduces the
    | chance that unrelated tasks share the same physical queue (and the same
    | "clear queue" blast radius). Capped in code to avoid oversized worker CLI.
    |
    */

    'shard_count' => (int) env('TASK_JOB_QUEUE_SHARD_COUNT', 4),

];
