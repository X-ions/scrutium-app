# SocialHub CMS Architecture

## Overview

SocialHub CMS is a multi-tenant social media management platform built on Laravel 12. It enables organizations to connect social media accounts, create content, publish to multiple platforms, and track analytics from a unified dashboard.

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        SocialHub CMS                                │
├─────────────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌───────────┐  │
│  │   Web App   │  │   API       │  │   Workers   │  │  Scheduler│  │
│  │  (Blade +   │  │  (REST/     │  │  (Queue     │  │  (Cron    │  │
│  │  Alpine.js) │  │   GraphQL)  │  │   Workers)  │  │   Jobs)   │  │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘  └─────┬─────┘  │
│         │                │                │                │        │
│         └────────────────┼────────────────┼────────────────┘        │
│                          ▼                ▼                          │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │                    Application Core                           │   │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌────────────────┐  │   │
│  │  │ Auth &   │ │ Social   │ │ Content  │ │ Analytics      │  │   │
│  │  │ Tenancy  │ │ Provider │ │ Manager  │ │ Engine         │  │   │
│  │  │          │ │ Registry │ │          │ │                │  │   │
│  │  └──────────┘ └────┬─────┘ └──────────┘ └────────────────┘  │   │
│  │                   │                                          │   │
│  │  ┌────────────────▼──────────────────────────────────────┐   │   │
│  │  │              Social Provider Interface                 │   │   │
│  │  │  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────────┐  │   │   │
│  │  │  │Facebook │ │Instagram│ │YouTube  │ │TikTok      │  │   │   │
│  │  │  │Provider │ │Provider │ │Provider │ │Provider    │  │   │   │
│  │  │  └─────────┘ └─────────┘ └─────────┘ └─────────────┘  │   │   │
│  │  │  ┌─────────┐ ┌─────────┐ ┌─────────┐                  │   │   │
│  │  │  │LinkedIn │ │X        │ │Pinterest│                  │   │   │
│  │  │  │Provider │ │Provider │ │Provider │                  │   │   │
│  │  │  └─────────┘ └─────────┘ └─────────┘                  │   │   │
│  │  └──────────────────────────────────────────────────────┘   │   │
│  └──────────────────────────────────────────────────────────────┘   │
│                          │                │                          │
│         ┌────────────────┼────────────────┼────────────────┐        │
│         ▼                ▼                ▼                ▼        │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐  │
│  │  Database   │ │  Redis/     │ │  Object     │ │  External   │  │
│  │  (MySQL/    │ │  Queue      │ │  Storage    │ │  APIs       │  │
│  │  PostgreSQL)│ │             │ │  (S3/R2)    │ │             │  │
│  └─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

## Core Components

### 1. Multi-Tenancy
- **Tenant** = Organization
- Each tenant has isolated data (social accounts, posts, media, analytics)
- Row-level security via `tenant_id` on all models
- `BelongsToTenant` trait enforces scoping

### 2. Authentication & Authorization
- Laravel Sanctum for API tokens
- Session-based auth for web
- Role-based access control (Owner, Admin, Manager, Analyst, Viewer)
- Policy-based authorization for resources

### 3. Social Provider Architecture (Pluggable)
```
SocialProviderInterface
├── authenticate()
├── refreshToken()
├── getAccount()
├── getPages()
├── createPost()
├── publishPost()
├── uploadMedia()
├── deletePost()
├── getPost()
├── getComments()
├── replyToComment()
├── getAnalytics()
├── getFollowers()
├── getSupportedFeatures()
└── disconnect()
```

Each provider declares capabilities via `ProviderCapabilities` enum.

### 4. Content Management
- **Post** - Master content with platform-specific variants
- **PostVariant** - Platform-specific customization (caption, media, hashtags)
- **MediaAsset** - Files stored in object storage (S3/R2/local)
- **ScheduledPost** - Publishing schedule with status tracking

### 5. Publishing Engine
- Background jobs via Laravel Queue (Redis)
- Each platform variant publishes independently
- Retry with exponential backoff
- Idempotency via job IDs
- Dead letter queue for permanent failures

### 6. Analytics Engine
- Raw metrics stored per platform/post/account
- Aggregated snapshots for dashboards
- Time-series data for trends
- Provider-specific metric definitions

### 7. Comments & Engagement
- Unified inbox across platforms
- Platform-specific reply capabilities
- Webhook handling for real-time updates

## Data Flow

### Publishing Flow
```
User creates post
       ↓
Save Post + Variants (DRAFT)
       ↓
User clicks Schedule/Publish
       ↓
Create ScheduledPost records per variant
       ↓
Queue PublishJob per variant
       ↓
Worker picks up job
       ↓
Provider.publishPost()
       ↓
Success: Update status PUBLISHED, store provider post ID
Failure: Retry with backoff, mark FAILED after max retries
```

### Analytics Sync Flow
```
Scheduler triggers AnalyticsSyncJob
       ↓
For each connected account
       ↓
Provider.getAnalytics()
       ↓
Store raw + normalized metrics
       ↓
Update aggregated snapshots
```

## Technology Stack

| Layer | Technology |
|-------|------------|
| Framework | Laravel 12 |
| Language | PHP 8.3+ |
| Frontend | Blade + Alpine.js + Tailwind CSS v4 |
| Database | MySQL/PostgreSQL |
| Queue | Redis + Laravel Horizon |
| Cache | Redis |
| Storage | S3-compatible (AWS S3, Cloudflare R2, MinIO) |
| Auth | Laravel Sanctum |
| Testing | Pest PHP |
| Linting | Pint (PHP), Biome (JS) |
| Build | Vite 7 |

## Directory Structure (New Additions)

```
app/
├── Services/
│   ├── Social/
│   │   ├── Contracts/
│   │   │   ├── SocialProviderInterface.php
│   │   │   └── ProviderCapabilities.php
│   │   ├── Providers/
│   │   │   ├── FacebookProvider.php
│   │   │   ├── InstagramProvider.php
│   │   │   ├── YouTubeProvider.php
│   │   │   ├── TikTokProvider.php
│   │   │   ├── XProvider.php
│   │   │   ├── LinkedInProvider.php
│   │   │   └── PinterestProvider.php
│   │   ├── SocialProviderRegistry.php
│   │   ├── Publishing/
│   │   │   ├── PublishingService.php
│   │   │   ├── PublishJob.php
│   │   │   └── PublishAttempt.php
│   │   ├── Analytics/
│   │   │   ├── AnalyticsService.php
│   │   │   ├── AnalyticsSyncJob.php
│   │   │   └── MetricNormalizer.php
│   │   └── Comments/
│   │       ├── CommentService.php
│   │       └── ReplyJob.php
│   ├── Media/
│   │   ├── MediaService.php
│   │   └── StorageAdapter.php
│   └── Tenant/
│       └── TenantService.php
├── Models/
│   ├── SocialAccount.php
│   ├── SocialAccountToken.php
│   ├── Post.php
│   ├── PostVariant.php
│   ├── MediaAsset.php
│   ├── ScheduledPost.php
│   ├── PublishingAttempt.php
│   ├── AnalyticsMetric.php
│   ├── AnalyticsSnapshot.php
│   ├── Comment.php
│   ├── CommentReply.php
│   └── Notification.php
├── Jobs/
│   ├── PublishPostJob.php
│   ├── SyncAnalyticsJob.php
│   ├── RefreshTokenJob.php
│   └── ProcessWebhookJob.php
├── Events/
│   ├── PostPublished.php
│   ├── PostFailed.php
│   ├── CommentReceived.php
│   └── TokenExpired.php
├── Http/
│   ├── Controllers/
│   │   ├── Social/
│   │   │   ├── SocialAccountController.php
│   │   │   ├── PostController.php
│   │   │   ├── MediaController.php
│   │   │   ├── CalendarController.php
│   │   │   ├── AnalyticsController.php
│   │   │   └── CommentController.php
│   │   └── Api/
│   │       └── SocialApiController.php
│   └── Requests/
│       ├── StorePostRequest.php
│       ├── StoreMediaRequest.php
│       └── PublishPostRequest.php
└── Policies/
    ├── SocialAccountPolicy.php
    ├── PostPolicy.php
    └── MediaAssetPolicy.php
```

## Key Design Principles

1. **Pluggable Providers** - Never use `if platform === 'facebook'` in core logic
2. **Tenant Isolation** - Every query scoped to tenant
3. **Async First** - All external API calls in background jobs
4. **Idempotency** - All jobs can be safely retried
5. **Observability** - Structured logging, job tracking, health checks
5. **Security First** - Encrypted tokens, webhook verification, rate limiting
6. **Graceful Degradation** - One provider failure doesn't block others