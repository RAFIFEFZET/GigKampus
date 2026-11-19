<?php

namespace App\Enums;

enum ChatRoomStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
