import './bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('workspaceNameAvailability', (endpoint, initialName = '') => ({
    name: initialName,
    status: 'idle',
    debounceTimer: null,
    controller: null,

    init() {
        this.check();
    },

    check() {
        window.clearTimeout(this.debounceTimer);
        this.controller?.abort();

        const candidate = this.name.trim();

        if (!candidate) {
            this.status = 'idle';
            return;
        }

        this.status = 'checking';
        this.debounceTimer = window.setTimeout(async () => {
            this.controller = new AbortController();

            try {
                const url = new URL(endpoint, window.location.origin);
                url.searchParams.set('name', candidate);
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    signal: this.controller.signal,
                });

                if (!response.ok) throw new Error('Workspace name check failed.');

                const result = await response.json();
                if (candidate === this.name.trim()) {
                    this.status = result.available ? 'available' : 'taken';
                }
            } catch (error) {
                if (error.name !== 'AbortError' && candidate === this.name.trim()) {
                    this.status = 'unavailable';
                }
            }
        }, 300);
    },
}));

Alpine.data('campaignTools', ({ createUrl, applyUrl, currency = 'USD', creators = [] }) => ({
    createUrl,
    applyUrl,
    currency,
    creators,
    productName: '',
    goal: 'Awareness',
    platform: 'Instagram Reels',
    budget: 15000,
    targetReach: 500000,
    microShare: 60,
    macroShare: 10,
    usageMonths: 6,
    exclusivityDays: 30,
    revisionRounds: 2,
    paidAmplification: false,
    repurposeContent: true,
    captionText: '',
    generatedBrief: '',
    campaignId: '',
    applyBudget: false,
    presetName: '',
    packageDeliverables: ['short_video', 'story_frames'],
    nicheFocus: '',
    matcherPlatform: 'any',
    matcherCountry: 'any',
    matcherGoal: 'Awareness',

    presets: {
        awareness: {
            name: 'Brand lift',
            goal: 'Awareness',
            budget: 15000,
            packageDeliverables: ['short_video', 'story_frames'],
            angle: 'Show the product in a natural routine, lead with a memorable use case, and close with a clear brand takeaway.',
        },
        conversion: {
            name: 'Conversion push',
            goal: 'Conversions',
            budget: 20000,
            packageDeliverables: ['demo', 'story_frames'],
            angle: 'Demonstrate the product benefit, address one common objection, and give viewers a specific next step.',
        },
        ugc: {
            name: 'UGC library',
            goal: 'UGC production',
            budget: 10000,
            packageDeliverables: ['short_video', 'raw_footage', 'still_images'],
            angle: 'Capture authentic product use, varied opening hooks, and a concise benefit-led close for future testing.',
        },
    },

    get currencySymbol() {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: this.currency }).formatToParts(0).find((part) => part.type === 'currency')?.value ?? '$';
    },

    get midShare() {
        return Math.max(0, 100 - this.microShare - this.macroShare);
    },

    get blendedCpm() {
        return 12 * (this.microShare / 100) + 20 * (this.midShare / 100) + 32 * (this.macroShare / 100);
    },

    get estimatedImpressions() {
        return this.blendedCpm > 0 ? Math.round((Number(this.budget) / this.blendedCpm) * 1000) : 0;
    },

    get engagementLow() {
        return Math.round(this.estimatedImpressions * 0.025);
    },

    get engagementHigh() {
        return Math.round(this.estimatedImpressions * 0.045);
    },

    get estimatedCpe() {
        const averageEngagements = (this.engagementLow + this.engagementHigh) / 2;
        return averageEngagements > 0 ? Number(this.budget) / averageEngagements : 0;
    },

    get suggestedRate() {
        return 400 * (this.microShare / 100) + 1600 * (this.midShare / 100) + 8000 * (this.macroShare / 100);
    },

    get reachAttainment() {
        return Math.min(100, this.targetReach > 0 ? Math.round((this.estimatedImpressions / this.targetReach) * 100) : 0);
    },

    get disclosurePresent() {
        return /(^|\s)#(?:ad|sponsored)\b|paid partnership|advertisement/i.test(this.captionText);
    },

    get newCampaignUrl() {
        const url = new URL(this.createUrl, window.location.origin);
        const baseName = this.productName.trim() || this.presetName || 'Creator campaign';
        url.searchParams.set('name', `${baseName} campaign`);
        url.searchParams.set('objective', this.goal);
        url.searchParams.set('brief', this.generatedBrief);
        url.searchParams.set('budget_total', this.budget || 0);
        return url.toString();
    },

    get creatorMatches() {
        return this.creators
            .filter((creator) => this.matcherPlatform === 'any' || creator.platform === this.matcherPlatform)
            .filter((creator) => this.matcherCountry === 'any' || creator.country === this.matcherCountry)
            .sort((first, second) => this.creatorFit(second) - this.creatorFit(first))
            .slice(0, 6);
    },

    formatMoney(value) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: this.currency,
            maximumFractionDigits: 2,
        }).format(Number(value) || 0);
    },

    formatCompact(value) {
        return new Intl.NumberFormat('en-US', {
            notation: 'compact',
            maximumFractionDigits: 1,
        }).format(Number(value) || 0);
    },

    creatorFit(creator) {
        const audienceSignal = this.matcherGoal === 'Conversions'
            ? Number(creator.engagement) * 12
            : Math.log10(Number(creator.followers) + 1) * 3;

        return Number(creator.pulse) + audienceSignal;
    },

    creatorFitLabel(creator) {
        const fit = this.creatorFit(creator);
        return fit >= 90 ? 'Strong fit' : fit >= 65 ? 'Good fit' : 'Reach fit';
    },

    applyPreset(key) {
        const preset = this.presets[key];
        if (!preset) return;

        this.presetName = preset.name;
        this.goal = preset.goal;
        this.budget = preset.budget;
        this.packageDeliverables = [...preset.packageDeliverables];
        this.generateBrief();
    },

    normalizeMix(changedTier) {
        if (this.microShare + this.macroShare <= 100) return;

        if (changedTier === 'micro') {
            this.macroShare = 100 - this.microShare;
        } else {
            this.microShare = 100 - this.macroShare;
        }
    },

    generateBrief() {
        const product = this.productName.trim() || 'Your product';
        const preset = Object.values(this.presets).find((item) => item.name === this.presetName);
        const packageLabels = {
            short_video: 'One creator-led short-form video with a clear hook and product demonstration.',
            story_frames: 'Two supporting story frames with a direct call to action.',
            raw_footage: 'One clean, unedited source video for brand editing.',
            still_images: 'Three edited product and lifestyle still images.',
            demo: 'A concise product walkthrough that demonstrates the primary benefit.',
        };
        const deliverables = this.packageDeliverables.length
            ? this.packageDeliverables.map((deliverable) => packageLabels[deliverable]).join('\n')
            : this.goal === 'UGC production'
                ? 'One vertical creator video with a clean export, clear usage permissions, and an unedited source file.'
                : 'One creator-led short-form video with a clear opening hook, product demonstration, and call to action.';
        const angle = preset?.angle ?? (this.goal === 'Conversions'
            ? 'Show the product benefit, answer a likely customer question, and use a clear trackable call to action.'
            : this.goal === 'UGC production'
                ? 'Keep the delivery natural, show the product in use, and provide clean, caption-free source footage.'
                : 'Introduce the product in a natural setting and focus on one memorable brand benefit.');
        const clauses = [
            `Usage rights: ${this.usageMonths} months${this.paidAmplification ? ', including paid amplification' : ', organic brand use only'}.`,
            `Category exclusivity: ${this.exclusivityDays ? `${this.exclusivityDays} days` : 'none'}.`,
            `Included revision rounds: ${Math.max(0, Number(this.revisionRounds) || 0)}.`,
            `Content repurposing: ${this.repurposeContent ? 'permitted on brand channels' : 'not included'}.`,
        ];

        this.generatedBrief = [
            `CREATOR CAMPAIGN BRIEF — ${product}`,
            '',
            `Campaign goal: ${this.goal}`,
            `Primary platform: ${this.platform}`,
            `Audience / niche focus: ${this.nicheFocus.trim() || 'Define the priority audience with the campaign owner.'}`,
            `Working budget: ${this.formatMoney(this.budget)}`,
            this.presetName ? `Starting template: ${this.presetName}` : '',
            '',
            'DELIVERABLES',
            deliverables,
            '',
            'KEY MESSAGE & CREATIVE DIRECTION',
            angle,
            `Talking point: Explain how ${product} fits into a real customer routine and support claims with an accurate, firsthand demonstration.`,
            '',
            'BRAND GUIDELINES',
            'Use the approved product name and current brand assets. Keep claims accurate, avoid unsupported performance promises, and disclose any material connection clearly and visibly.',
            '',
            'TRACKING & DISCLOSURE',
            'Use the campaign-specific tracking link or discount code supplied by the campaign owner. Include a clear #ad or paid partnership disclosure where required.',
            '',
            'USAGE & REVIEW TERMS',
            ...clauses,
        ].filter((line) => line !== '').join('\n');
    },

    addDisclosure() {
        const caption = this.captionText.trim();
        this.captionText = caption ? `#ad ${caption}` : '#ad ';
    },
}));

Alpine.start();
