<?php

namespace App\Policies;

use App\Models\Upload;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UploadPolicy
{
    /**
     * See the upload, its received chunks and its subtitles.
     */
    public function view(User $user, Upload $upload): Response
    {
        return $this->owner($user, $upload);
    }

    /**
     * Send chunks or complete the upload.
     */
    public function update(User $user, Upload $upload): Response
    {
        return $this->owner($user, $upload);
    }

    /**
     * Cancel or delete the upload.
     */
    public function delete(User $user, Upload $upload): Response
    {
        return $this->owner($user, $upload);
    }

    /**
     * Someone else's upload answers 404, so its id can't be probed for existence.
     */
    private function owner(User $user, Upload $upload): Response
    {
        return $upload->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
