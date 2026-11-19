<?php

namespace App\Enums;

enum RevisionStatus: string
{
    case Requested = 'requested';
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
