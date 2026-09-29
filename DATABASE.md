# SocialHub CMS Database Schema

## Overview

Normalized relational schema designed for multi-tenancy, social account management, content publishing, analytics, and engagement tracking.

## Entity Relationship Diagram

```
┌─────────────┐       ┌─────────────┐       ┌─────────────┐
│   tenants   │───────│    users    │       │   roles     │
└─────────────┘       └─────────────┘       └─────────────┘
       │                     │                     ▲
       │                     │                     │
       ▼                     ▼                     │
┌─────────────────┐ ┌─────────────────┐           │
│ social_accounts │ │  media_assets   │           │
└────────┬────────┘ └────────┬────────┘           │
         │                   │                    │
         ▼                   │                    │
┌─────────────────┐         │                    │
│social_account_  │         │                    │
│     tokens      │         │                    │
└────────┬────────┘         │                    │
         │                  │                    │
         ▼                  ▼                    │
┌─────────────────┐ ┌─────────────────┐         │
│     posts       │ │ post_variants   │         │
└────────┬────────┘ └────────┬────────┘         │
         │                   │                  │
         ▼                   ▼                  │
┌─────────────────┐ ┌─────────────────┐         │
│ scheduled_posts │ │ publishing_     │         │
└─────────────────┘ │    attempts     │         │
                    └─────────────────┘         │
                            │                   │
                            ▼                   │
                    ┌─────────────────┐         │
                    │   comments      │         │
                    └────────┬────────┘         │
                             │                  │
                             ▼                  │
                    ┌─────────────────┐         │
                    │ comment_replies │         │
                    └─────────────────┘         │
                            │                   │
                            ▼                   │
                    ┌─────────────────┐         │
                    │ analytics_      │         │
                    │    metrics      │         │
                    └────────┬────────┘         │
                             │                  │
                             ▼                  │
                    ┌─────────────────┐         │
                    │analytics_       │         │
                    │  snapshots      │         │
                    └─────────────────┘         │
                            │                   │
                            ▼                   │
                    ┌─────────────────┐         │
                    │  notifications  │─────────┘
                    └─────────────────┘
```

## Tables

### 1. tenants (Existing - Extended)
```sql
-- Existing table extended with SocialHub fields
ALTER TABLE tenants ADD COLUMN plan VARCHAR(50) DEFAULT 'free';
ALTER TABLE tenants ADD COLUMN social_accounts_limit INT DEFAULT 3;
ALTER TABLE tenants ADD COLUMN scheduled_posts_limit INT DEFAULT 10;
ALTER TABLE tenants ADD COLUMN team_members_limit INT DEFAULT 5;
ALTER TABLE tenants ADD COLUMN analytics_retention_days INT DEFAULT 30;
ALTER TABLE tenants ADD COLUMN storage_limit_mb INT DEFAULT 1024;
ALTER TABLE tenants ADD COLUMN ai_credits_monthly INT DEFAULT 0;
```

### 2. users (Existing - Extended)
```sql
-- Existing table, roles already defined in UserRole enum
-- No changes needed, uses BelongsToTenant trait
```

### 3. social_accounts
```sql
CREATE TABLE social_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL, -- 'facebook', 'instagram', 'youtube', 'tiktok', 'x', 'linkedin', 'pinterest'
    provider_account_id VARCHAR(255) NOT NULL, -- Platform's internal account ID
    provider_username VARCHAR(255), -- @handle or page name
    provider_display_name VARCHAR(255), -- Display name
    provider_avatar_url VARCHAR(500),
    account_type ENUM('personal', 'business', 'creator', 'page', 'channel') NOT NULL,
    status ENUM('connected', 'expired', 'revoked', 'error', 'disconnected') DEFAULT 'disconnected',
    permissions JSON, -- Granted permissions/scopes
    capabilities JSON, -- Declared provider capabilities for this account
    is_default BOOLEAN DEFAULT FALSE,
    last_synced_at TIMESTAMP NULL,
    last_error TEXT NULL,
    metadata JSON, -- Platform-specific additional data
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    UNIQUE KEY unique_tenant_provider_account (tenant_id, provider, provider_account_id),
    INDEX idx_tenant_provider (tenant_id, provider),
    INDEX idx_status (status),
    INDEX idx_default (tenant_id, is_default),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4. social_account_tokens
```sql
CREATE TABLE social_account_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    social_account_id BIGINT UNSIGNED NOT NULL,
    access_token TEXT NOT NULL, -- Encrypted
    refresh_token TEXT NULL, -- Encrypted
    token_type VARCHAR(50) DEFAULT 'Bearer',
    expires_at TIMESTAMP NULL,
    scope TEXT NULL, -- Space-separated scopes
    id_token TEXT NULL, -- For OIDC providers
    metadata JSON NULL, -- Provider-specific token data
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE,
    INDEX idx_account_expires (social_account_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5. posts (Master content)
```sql
CREATE TABLE posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL, -- Author
    title VARCHAR(255) NULL, -- Internal title
    status ENUM('draft', 'scheduled', 'publishing', 'published', 'failed', 'cancelled') DEFAULT 'draft',
    campaign_id BIGINT UNSIGNED NULL, -- Optional campaign grouping
    tags JSON NULL, -- User-defined tags
    notes TEXT NULL, -- Internal notes
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_tenant_user (tenant_id, user_id),
    INDEX idx_campaign (campaign_id),
    INDEX idx_published_at (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6. post_variants (Platform-specific content)
```sql
CREATE TABLE post_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    social_account_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL,
    caption TEXT NULL,
    media_ids JSON NULL, -- Array of media_asset IDs
    hashtags JSON NULL, -- Array of hashtags
    mentions JSON NULL, -- Array of @mentions
    location_id VARCHAR(255) NULL, -- Platform location ID
    location_name VARCHAR(255) NULL,
    platform_specific JSON NULL, -- Provider-specific fields (e.g., YouTube title/description/tags)
    status ENUM('pending', 'scheduled', 'publishing', 'published', 'failed', 'cancelled') DEFAULT 'pending',
    provider_post_id VARCHAR(255) NULL, -- Platform's post ID after publishing
    provider_post_url VARCHAR(500) NULL,
    error_message TEXT NULL,
    retry_count TINYINT UNSIGNED DEFAULT 0,
    scheduled_at TIMESTAMP NULL,
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_post_account (post_id, social_account_id),
    INDEX idx_post_status (post_id, status),
    INDEX idx_account_scheduled (social_account_id, scheduled_at),
    INDEX idx_provider_post_id (provider_post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 7. media_assets
```sql
CREATE TABLE media_assets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL, -- Uploader
    filename VARCHAR(255) NOT NULL, -- Original filename
    stored_filename VARCHAR(255) NOT NULL, -- Storage path/key
    mime_type VARCHAR(100) NOT NULL, -- image/jpeg, video/mp4, etc.
    file_size BIGINT UNSIGNED NOT NULL, -- Bytes
    width INT UNSIGNED NULL, -- For images/video
    height INT UNSIGNED NULL,
    duration DECIMAL(10,3) NULL, -- Seconds for video/audio
    storage_disk VARCHAR(50) NOT NULL, -- 's3', 'r2', 'local'
    storage_path VARCHAR(500) NOT NULL, -- Full storage path
    thumbnail_path VARCHAR(500) NULL, -- Generated thumbnail
    alt_text VARCHAR(255) NULL,
    tags JSON NULL,
    folder VARCHAR(255) NULL, -- Virtual folder
    metadata JSON NULL, -- EXIF, video metadata, etc.
    usage_count INT UNSIGNED DEFAULT 0,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_tenant_type (tenant_id, mime_type),
    INDEX idx_tenant_folder (tenant_id, folder),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 8. post_media (Pivot - many-to-many)
```sql
CREATE TABLE post_media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_variant_id BIGINT UNSIGNED NOT NULL,
    media_asset_id BIGINT UNSIGNED NOT NULL,
    sort_order INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (post_variant_id) REFERENCES post_variants(id) ON DELETE CASCADE,
    FOREIGN KEY (media_asset_id) REFERENCES media_assets(id) ON DELETE CASCADE,
    UNIQUE KEY unique_variant_media (post_variant_id, media_asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 9. scheduled_posts
```sql
CREATE TABLE scheduled_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_variant_id BIGINT UNSIGNED NOT NULL,
    scheduled_at TIMESTAMP NOT NULL,
    timezone VARCHAR(50) NOT NULL DEFAULT 'UTC',
    status ENUM('pending', 'queued', 'processing', 'published', 'failed', 'cancelled') DEFAULT 'pending',
    job_id VARCHAR(255) NULL, -- Queue job ID for tracking
    attempts TINYINT UNSIGNED DEFAULT 0,
    max_attempts TINYINT UNSIGNED DEFAULT 3,
    last_error TEXT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (post_variant_id) REFERENCES post_variants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_variant_schedule (post_variant_id),
    INDEX idx_scheduled_at (scheduled_at, status),
    INDEX idx_job_id (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 10. publishing_attempts
```sql
CREATE TABLE publishing_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_variant_id BIGINT UNSIGNED NOT NULL,
    scheduled_post_id BIGINT UNSIGNED NULL,
    attempt_number TINYINT UNSIGNED NOT NULL,
    status ENUM('pending', 'processing', 'success', 'failed', 'rate_limited') NOT NULL,
    request_payload JSON NULL, -- Sanitized request
    response_payload JSON NULL, -- Sanitized response
    error_code VARCHAR(100) NULL,
    error_message TEXT NULL,
    rate_limit_reset_at TIMESTAMP NULL,
    started_at TIMESTAMP NOT NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (post_variant_id) REFERENCES post_variants(id) ON DELETE CASCADE,
    FOREIGN KEY (scheduled_post_id) REFERENCES scheduled_posts(id) ON DELETE SET NULL,
    INDEX idx_variant_attempt (post_variant_id, attempt_number),
    INDEX idx_status_started (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 11. analytics_metrics (Raw metrics)
```sql
CREATE TABLE analytics_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    social_account_id BIGINT UNSIGNED NOT NULL,
    post_variant_id BIGINT UNSIGNED NULL, -- NULL for account-level metrics
    provider VARCHAR(50) NOT NULL,
    metric_type VARCHAR(100) NOT NULL, -- 'views', 'likes', 'comments', 'shares', 'followers', etc.
    metric_subtype VARCHAR(100) NULL, -- 'organic', 'paid', 'viral', etc.
    period_start TIMESTAMP NOT NULL,
    period_end TIMESTAMP NOT NULL,
    value BIGINT NOT NULL DEFAULT 0,
    raw_response JSON NULL, -- Full provider response for debugging
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (post_variant_id) REFERENCES post_variants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_metric (tenant_id, social_account_id, post_variant_id, provider, metric_type, metric_subtype, period_start),
    INDEX idx_tenant_account_period (tenant_id, social_account_id, period_start),
    INDEX idx_variant_period (post_variant_id, period_start),
    INDEX idx_provider_metric (provider, metric_type),
    INDEX idx_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci PARTITION BY RANGE (TO_DAYS(period_start)) (
    PARTITION p2024 VALUES LESS THAN (TO_DAYS('2025-01-01')),
    PARTITION p2025 VALUES LESS THAN (TO_DAYS('2026-01-01')),
    PARTITION p2026 VALUES LESS THAN (TO_DAYS('2027-01-01')),
    PARTITION pmax VALUES LESS THAN MAXVALUE
);
```

### 12. analytics_snapshots (Aggregated for dashboards)
```sql
CREATE TABLE analytics_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    social_account_id BIGINT UNSIGNED NULL, -- NULL for tenant-level
    post_variant_id BIGINT UNSIGNED NULL,
    provider VARCHAR(50) NULL, -- NULL for cross-platform
    date DATE NOT NULL,
    period ENUM('hourly', 'daily', 'weekly', 'monthly') NOT NULL,
    metrics JSON NOT NULL, -- {views: 100, likes: 10, ...}
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (post_variant_id) REFERENCES post_variants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_snapshot (tenant_id, social_account_id, post_variant_id, provider, date, period),
    INDEX idx_tenant_date (tenant_id, date, period),
    INDEX idx_account_date (social_account_id, date, period),
    INDEX idx_variant_date (post_variant_id, date, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 13. comments
```sql
CREATE TABLE comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    post_variant_id BIGINT UNSIGNED NOT NULL,
    social_account_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL,
    provider_comment_id VARCHAR(255) NOT NULL,
    parent_comment_id BIGINT UNSIGNED NULL, -- For replies/threads
    author_provider_id VARCHAR(255) NOT NULL,
    author_username VARCHAR(255) NULL,
    author_display_name VARCHAR(255) NULL,
    author_avatar_url VARCHAR(500) NULL,
    content TEXT NOT NULL,
    like_count INT UNSIGNED DEFAULT 0,
    reply_count INT UNSIGNED DEFAULT 0,
    is_hidden BOOLEAN DEFAULT FALSE,
    is_deleted BOOLEAN DEFAULT FALSE,
    provider_created_at TIMESTAMP NOT NULL,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (post_variant_id) REFERENCES post_variants(id) ON DELETE CASCADE,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_comment_id) REFERENCES comments(id) ON DELETE SET NULL,
    UNIQUE KEY unique_provider_comment (provider, provider_comment_id),
    INDEX idx_variant_created (post_variant_id, provider_created_at),
    INDEX idx_account_created (social_account_id, provider_created_at),
    INDEX idx_parent (parent_comment_id),
    INDEX idx_hidden (is_hidden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 14. comment_replies
```sql
CREATE TABLE comment_replies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comment_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL, -- Internal user who replied
    social_account_id BIGINT UNSIGNED NOT NULL, -- Account used to reply
    provider VARCHAR(50) NOT NULL,
    content TEXT NOT NULL,
    provider_reply_id VARCHAR(255) NULL,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE,
    INDEX idx_comment_status (comment_id, status),
    INDEX idx_provider_reply (provider, provider_reply_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 15. notifications
```sql
CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL, -- NULL = broadcast to tenant
    type VARCHAR(100) NOT NULL, -- 'post.published', 'post.failed', 'token.expired', 'comment.new', etc.
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    data JSON NULL, -- Additional context
    action_url VARCHAR(500) NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    priority ENUM('low', 'normal', 'high', 'critical') DEFAULT 'normal',
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_tenant_user_read (tenant_id, user_id, is_read),
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_type (type),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 16. webhook_events (For idempotency)
```sql
CREATE TABLE webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL,
    event_id VARCHAR(255) NOT NULL, -- Provider's event ID
    event_type VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    processed BOOLEAN DEFAULT FALSE,
    processed_at TIMESTAMP NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_provider_event (provider, event_id),
    INDEX idx_tenant_processed (tenant_id, processed),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 17. oauth_states (For PKCE/state validation)
```sql
CREATE TABLE oauth_states (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL,
    state VARCHAR(255) NOT NULL,
    code_verifier VARCHAR(255) NULL, -- For PKCE
    redirect_url VARCHAR(500) NULL,
    scopes TEXT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_state (state),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 18. audit_logs
```sql
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    event VARCHAR(100) NOT NULL, -- 'social_account.connected', 'post.published', etc.
    auditable_type VARCHAR(255) NULL,
    auditable_id BIGINT UNSIGNED NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tenant_event (tenant_id, event),
    INDEX idx_auditable (auditable_type, auditable_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Indexes Summary

| Table | Key Indexes |
|-------|-------------|
| social_accounts | (tenant_id, provider), (status), (tenant_id, is_default) |
| social_account_tokens | (social_account_id, expires_at) |
| posts | (tenant_id, status), (tenant_id, user_id), (campaign_id) |
| post_variants | (post_id, status), (social_account_id, scheduled_at) |
| media_assets | (tenant_id, mime_type), (tenant_id, folder) |
| scheduled_posts | (scheduled_at, status), (job_id) |
| publishing_attempts | (post_variant_id, attempt_number) |
| analytics_metrics | (tenant_id, social_account_id, period_start), (post_variant_id, period_start) - PARTITIONED |
| analytics_snapshots | (tenant_id, date, period), (social_account_id, date, period) |
| comments | (post_variant_id, provider_created_at), (social_account_id, provider_created_at) |
| webhook_events | (provider, event_id) UNIQUE |

## Soft Deletes
Tables with `deleted_at`: tenants, users, social_accounts, posts, post_variants, media_assets, comments

## Partitioning
- `analytics_metrics` partitioned by `period_start` (monthly partitions)
- Retention policy: Drop partitions older than tenant's `analytics_retention_days`

## Migrations Order
1. social_accounts
2. social_account_tokens
3. posts
4. post_variants
5. media_assets
6. post_media
7. scheduled_posts
8. publishing_attempts
9. analytics_metrics
10. analytics_snapshots
11. comments
12. comment_replies
13. notifications
13. webhook_events
14. oauth_states
15. audit_logs
16. Add columns to tenants