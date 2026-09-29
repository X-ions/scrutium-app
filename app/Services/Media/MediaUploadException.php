<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Services\Social\Data\UserFacingError;
use RuntimeException;

/**
 * An upload was refused before anything was written to storage.
 *
 * The reason is one of the shapes the upload validator produces; every one of
 * them is terminal, so the caller shows the message and never retries the same
 * bytes.
 */
class MediaUploadException extends RuntimeException
{
    public const REASON_TOO_LARGE = 'file_too_large';

    public const REASON_TYPE_NOT_ALLOWED = 'type_not_allowed';

    public const REASON_MIME_MISMATCH = 'mime_mismatch';

    public const REASON_DOUBLE_EXTENSION = 'double_extension';

    public const REASON_EMPTY = 'empty_file';

    public const REASON_UNREADABLE = 'unreadable_file';

    public const REASON_QUOTA_EXCEEDED = 'storage_quota_exceeded';

    public const REASON_IN_USE = 'asset_in_use';

    public const REASON_SANITISATION_FAILED = 'sanitisation_failed';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?UserFacingError $userFacingError = null,
    ) {
        parent::__construct($message);
    }

    public static function tooLarge(int $maxKb, int $actualKb): self
    {
        return new self(
            self::REASON_TOO_LARGE,
            sprintf('Upload of %d KB exceeds the %d KB limit.', $actualKb, $maxKb),
            UserFacingError::make(
                self::REASON_TOO_LARGE,
                sprintf('That file is larger than the %d MB limit for a single upload.', (int) round($maxKb / 1024)),
                sprintf('Rejected a %d KB upload against a %d KB cap.', $actualKb, $maxKb),
                false,
                'Compress the file, or split it into smaller pieces.',
            ),
        );
    }

    public static function typeNotAllowed(string $mimeType, string $extension): self
    {
        return new self(
            self::REASON_TYPE_NOT_ALLOWED,
            sprintf('MIME type "%s" with extension "%s" is not an accepted media type.', $mimeType, $extension),
            UserFacingError::make(
                self::REASON_TYPE_NOT_ALLOWED,
                'That file type cannot be uploaded. Use a JPEG, PNG, WebP, GIF, MP4, MOV or WebM file.',
                sprintf('Rejected MIME "%s" / extension "%s" against the configured allow-list.', $mimeType, $extension),
                false,
                'Convert the file to a supported image or video format.',
            ),
        );
    }

    public static function mimeMismatch(string $claimed, string $actual, string $extension): self
    {
        return new self(
            self::REASON_MIME_MISMATCH,
            sprintf('File extension "%s" does not match the file contents, which are "%s".', $extension, $actual),
            UserFacingError::make(
                self::REASON_MIME_MISMATCH,
                'That file does not look like the format its name claims, so it was rejected.',
                sprintf('Client-declared MIME "%s" differs from the sniffed MIME "%s" for extension "%s".', $claimed, $actual, $extension),
                false,
                'Re-upload the original file rather than a renamed copy.',
            ),
        );
    }

    public static function doubleExtension(string $filename): self
    {
        return new self(
            self::REASON_DOUBLE_EXTENSION,
            sprintf('Filename "%s" carries more than one extension.', $filename),
            UserFacingError::make(
                self::REASON_DOUBLE_EXTENSION,
                'That file name was rejected because it contains more than one file extension.',
                sprintf('Rejected double extension on "%s".', $filename),
                false,
                'Rename the file to a single extension and upload it again.',
            ),
        );
    }

    public static function empty(): self
    {
        return new self(
            self::REASON_EMPTY,
            'The uploaded file was empty.',
            UserFacingError::make(
                self::REASON_EMPTY,
                'That file is empty, so there was nothing to upload.',
                'The upload reported zero bytes.',
                false,
                'Choose a different file.',
            ),
        );
    }

    public static function unreadable(string $detail = ''): self
    {
        return new self(
            self::REASON_UNREADABLE,
            'The uploaded file could not be read or is not a supported media container.',
            UserFacingError::make(
                self::REASON_UNREADABLE,
                'That file could not be read, so it was not uploaded.',
                $detail === '' ? 'Uploaded bytes could not be sniffed as a media container.' : $detail,
                false,
                'Re-export the file, or upload it directly from the camera or editor.',
            ),
        );
    }

    public static function quotaExceeded(int $limitMb, int $usedMb, int $requestedMb): self
    {
        return new self(
            self::REASON_QUOTA_EXCEEDED,
            sprintf('Upload needs %d MB but the workspace has %d MB free of its %d MB allowance.', $requestedMb, max(0, $limitMb - $usedMb), $limitMb),
            UserFacingError::make(
                self::REASON_QUOTA_EXCEEDED,
                sprintf(
                    'This workspace is using %d MB of its %d MB media allowance. Delete an unused file or upgrade the plan to upload more.',
                    $usedMb,
                    $limitMb,
                ),
                sprintf('Tenant storage quota exceeded: %d MB used, %d MB requested against a %d MB limit.', $usedMb, $requestedMb, $limitMb),
                false,
                'Remove media that is not attached to a post, or upgrade the workspace plan.',
                ['limit_mb' => $limitMb, 'used_mb' => $usedMb, 'requested_mb' => $requestedMb],
            ),
        );
    }

    public static function inUse(int $references): self
    {
        return new self(
            self::REASON_IN_USE,
            sprintf('The asset is attached to %d post variant(s) and cannot be deleted.', $references),
            UserFacingError::make(
                self::REASON_IN_USE,
                'This file is still attached to a post, so it was not deleted.',
                sprintf('Refused to delete an asset referenced by %d post variant row(s).', $references),
                false,
                'Remove the file from the post first, then delete it.',
            ),
        );
    }

    public static function sanitisationFailed(string $detail): self
    {
        return new self(
            self::REASON_SANITISATION_FAILED,
            sprintf('Metadata could not be stripped from the upload: %s', $detail),
            UserFacingError::make(
                self::REASON_SANITISATION_FAILED,
                'That image still carries location data we could not remove, so it was not uploaded.',
                $detail,
                false,
                'Re-export the image without location metadata and upload it again.',
            ),
        );
    }

    public function userMessage(): string
    {
        return $this->userFacingError?->userMessage ?? $this->getMessage();
    }
}
