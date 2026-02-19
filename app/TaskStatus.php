<?php

namespace App;

enum TaskStatus
{
    case IN_PROGRESS;
    case COMPLETED;
    case PAUSED;
    case CANCELED;
}
