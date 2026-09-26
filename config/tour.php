<?php

/*
|--------------------------------------------------------------------------
| Product Tour
|--------------------------------------------------------------------------
|
| Step definitions for the "How it works" walkthrough. Every claim below is
| taken from the actual controllers, models and views, not from aspirational
| design notes. Steps are filtered by audience before they reach the UI so a
| read-only role is never told to click a button it cannot use.
|
| Step keys:
|   id        stable identifier, also used as the highlight label
|   icon      emoji shown in the step bubble
|   title     short headline
|   body      the explanation, one or two sentences
|   tip       optional "handy to know" footnote
|   route     route name this step belongs to; used for cross-page navigation
|   target    optional CSS selector to spotlight on that page
|   cta       optional label for the "take me there" button
|   audience  any | operator | admin
|
*/

return [

    'storage_key' => 'scrutium.tour.completed',

    'steps' => [

        [
            'id' => 'welcome',
            'icon' => '👋',
            'title' => 'Let us show you around',
            'body' => 'Scrutium tracks an influencer campaign from the first brief to the final invoice. '
                .'This tour covers the handful of things worth knowing before you dive in.',
            'tip' => 'You can leave at any time with Esc, and replay the tour any time from the guide in the header.',
            'route' => 'dashboard',
            'target' => null,
            'cta' => null,
            'audience' => 'any',
        ],

        [
            'id' => 'workspace',
            'icon' => '🏢',
            'title' => 'Everything lives in one workspace',
            'body' => 'Your workspace is the top-level container. Campaigns, creators, deliverables and '
                .'reports are all scoped to it, so you only ever see your own team\'s data — never another '
                .'workspace\'s.',
            'tip' => 'Workspace administrators manage teammates and roles in Settings.',
            'route' => 'dashboard',
            'target' => '#sidebar',
            'cta' => null,
            'audience' => 'any',
        ],

        [
            'id' => 'campaigns',
            'icon' => '🎯',
            'title' => 'A campaign has two dials',
            'body' => 'Stage tracks where the work is — Brief, Casting, Live, Reconcile, Completed. '
                .'Status tracks the commercial reality — Draft, Active, Paused, Completed or Cancelled.',
            'tip' => 'A campaign can be Live and Paused at the same time. The two axes are independent on purpose.',
            'route' => 'campaigns',
            'target' => null,
            'cta' => 'Open campaigns',
            'audience' => 'any',
        ],

        [
            'id' => 'roster',
            'icon' => '✅',
            'title' => 'Only vetted creators can be booked',
            'body' => 'Creators sit in a sourcing pipeline you move them through. Only a Vetted creator '
                .'shows up in a campaign\'s roster picker, so vetting is the gate that keeps sloppy '
                .'bookings out.',
            'tip' => 'Tier (Nano, Micro, Macro, Mega) is derived from follower count automatically — you never pick it.',
            'route' => 'influencers',
            'target' => null,
            'cta' => 'Open creators',
            'audience' => 'any',
        ],

        [
            'id' => 'deliverables',
            'icon' => '📦',
            'title' => 'A deliverable is one contracted unit',
            'body' => 'One creator, one campaign, one owed piece of work — a Post, Story, Reel, Video, '
                .'Blog or Podcast. It carries a contracted unit count, an optional fee and an optional '
                .'due date.',
            'tip' => 'You can only create a deliverable for a creator who is already on that campaign\'s roster.',
            'route' => 'deliverables',
            'target' => null,
            'cta' => 'Open deliverables',
            'audience' => 'any',
        ],

        [
            'id' => 'lifecycle',
            'icon' => '🔁',
            'title' => 'Pending, then Submitted, then a verdict',
            'body' => 'A deliverable starts Pending. The creator submits it, which moves it to Submitted. '
                .'From there you either Approve it or Reject it with a reason — those two are final.',
            'tip' => 'Approved and Rejected deliverables cannot be resubmitted, and a Pending one cannot be judged yet.',
            'route' => 'deliverables',
            'target' => null,
            'cta' => 'See the worklist',
            'audience' => 'any',
        ],

        [
            'id' => 'evidence',
            'icon' => '🔒',
            'title' => 'Evidence is not optional',
            'body' => 'To move a deliverable to Submitted you must attach proof of delivery: an uploaded '
                .'file (JPG, PNG, PDF or MP4, up to 10 MB) or a link. A deliverable with no evidence '
                .'cannot be approved.',
            'tip' => 'Head to the deliverables worklist and tick "Overdue only" to chase what is running late.',
            'route' => 'deliverables',
            'target' => null,
            'cta' => 'Open a deliverable',
            'audience' => 'operator',
        ],

        [
            'id' => 'verification',
            'icon' => '⚖️',
            'title' => 'Approving moves real numbers',
            'body' => 'Approving credits at least the contracted units, stamps who verified it, and '
                .'recalculates the campaign\'s spend from the fees of every approved deliverable.',
            'tip' => 'Every approval, submission and rejection is written to the deliverable\'s audit trail.',
            'route' => 'deliverables',
            'target' => null,
            'cta' => 'Go verify',
            'audience' => 'operator',
        ],

        [
            'id' => 'content',
            'icon' => '🛡️',
            'title' => 'Content is checked, not trusted',
            'body' => 'Synced posts are screened for missing disclosure, missing captions and a low '
                .'provenance score. Anything that trips a rule is flagged for review.',
            'tip' => 'Missing captions alone will flag a post, which catches more people than you would expect.',
            'route' => 'content',
            'target' => null,
            'cta' => 'Open content',
            'audience' => 'any',
        ],

        [
            'id' => 'scoring',
            'icon' => '🎛️',
            'title' => 'Pulse score is five dials you control',
            'body' => 'Every creator is scored on engagement rate, audience quality, content relevance, '
                .'reliability and cost efficiency. The weights must total 1.0 — change them, then recalculate.',
            'tip' => 'Scores land in four bands: Elite (85+), Strong (70+), Fair (55+) and At risk.',
            'route' => 'scoring',
            'target' => null,
            'cta' => 'Tune scoring',
            'audience' => 'operator',
        ],

        [
            'id' => 'performance',
            'icon' => '📈',
            'title' => 'Performance, not vanity',
            'body' => 'Approved spend, reach, engagements and CPE, either for one campaign or across the '
                .'whole workspace. Pick a campaign from the dropdown to scope the numbers.',
            'route' => 'performance',
            'target' => null,
            'cta' => 'Open performance',
            'audience' => 'any',
        ],

        [
            'id' => 'reports',
            'icon' => '🧊',
            'title' => 'Reports freeze a moment in time',
            'body' => 'Generating a report snapshots the current campaign and deliverable state and freezes '
                .'it immediately. Download it later as a JSON snapshot — frozen reports do not drift.',
            'tip' => 'Version numbers run across the whole workspace, not per report, so they are a timeline rather than a per-document counter.',
            'route' => 'reports',
            'target' => null,
            'cta' => 'Open reports',
            'audience' => 'operator',
        ],

        [
            'id' => 'alerts',
            'icon' => '🚨',
            'title' => 'Triage, then resolve',
            'body' => 'Alerts arrive ordered by severity so the Critical ones surface first. Acknowledge '
                .'to claim one, resolve to close it. Your own notification subscriptions are on the '
                .'subscriptions tab.',
            'tip' => 'Click the Active or Paused pill to toggle a subscription — there is no checkbox to tick.',
            'route' => 'alerts',
            'target' => null,
            'cta' => 'Open alerts',
            'audience' => 'operator',
        ],

        [
            'id' => 'integrations',
            'icon' => '🔌',
            'title' => 'Connect the platforms you brief on',
            'body' => 'Save a provider with its access token first, then connect it. One connection per '
                .'provider per workspace.',
            'tip' => 'A connection cannot be activated without an access token saved against it.',
            'route' => 'integrations',
            'target' => null,
            'cta' => 'Open integrations',
            'audience' => 'operator',
        ],

        [
            'id' => 'roles',
            'icon' => '🎭',
            'title' => 'Your role decides what you can change',
            'body' => 'Owner and Admin can do everything, including Settings. Manager can run the whole '
                .'campaign loop but not administer the workspace. Analyst and Viewer are read-only.',
            'tip' => 'Read-only roles still see the buttons. Clicking one will be refused — that is the role working, not a bug.',
            'route' => 'dashboard',
            'target' => null,
            'cta' => null,
            'audience' => 'any',
        ],

        [
            'id' => 'settings',
            'icon' => '🔐',
            'title' => 'Workspace settings',
            'body' => 'Rename the workspace, pick a currency, manage members and their roles, and create '
                .'default notification subscriptions.',
            'tip' => 'Changing the currency does not re-denominate campaigns that already exist — those keep the currency they were created with.',
            'route' => 'settings',
            'target' => null,
            'cta' => 'Open settings',
            'audience' => 'admin',
        ],

        [
            'id' => 'finish',
            'icon' => '🎉',
            'title' => 'That is the whole loop',
            'body' => 'Brief a campaign, staff it with vetted creators, track deliverables through '
                .'verification, then read the outcome in performance, reports and alerts.',
            'tip' => 'You can replay this tour any time from the guide in the header.',
            'route' => null,
            'target' => null,
            'cta' => null,
            'audience' => 'any',
        ],

    ],

];
