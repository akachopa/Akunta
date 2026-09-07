<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Documents\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::DocumentView);
    }

    public function view(User $user, Document $document): bool
    {
        return $user->hasBusinessPermission($document->business_id, PermissionSlug::DocumentView);
    }

    public function create(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::DocumentUpload);
    }

    /**
     * Mengunduh berkas asli dokumen finansial.
     *
     * Disamakan dengan hak melihat dokumen: berkas asli adalah evidence yang harus dapat
     * dibuka siapa pun yang berwenang meninjau dokumennya (plan.md §35.3 evidence first).
     */
    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function reprocess(User $user, Document $document): bool
    {
        return $user->hasBusinessPermission($document->business_id, PermissionSlug::DocumentManage);
    }

    public function archive(User $user, Document $document): bool
    {
        return $user->hasBusinessPermission($document->business_id, PermissionSlug::DocumentManage);
    }

    /**
     * plan.md §44.7 melarang penghapusan jejak, dan dokumen adalah evidence journal.
     * Penghapusan permanen karena itu tidak diizinkan bagi siapa pun; arsip adalah
     * satu-satunya cara mengeluarkan dokumen dari inbox.
     */
    public function delete(User $user, Document $document): bool
    {
        return false;
    }
}
