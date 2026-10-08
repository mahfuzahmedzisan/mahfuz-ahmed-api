<?php

namespace App\Enums;

enum VideoStatus: string
{
    case AwaitingUpload = 'awaiting_upload';
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
