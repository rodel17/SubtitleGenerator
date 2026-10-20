<?php

namespace App\Enums;

enum UploadStatus: string
{
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
}
