<?php

namespace App;

enum TaskStatus
{
    case IN_PROGRESS;
    case COMPLETED;
    case TIMED_OUT;
    case PAUSED;
    case CANCELED;
}
