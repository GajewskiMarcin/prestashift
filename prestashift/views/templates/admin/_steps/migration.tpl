{* Step 4: Migration - Execution, Progress & Success *}

<div id="migration-start-container">
    <div class="ps-card ps-p-8 ps-text-center">
         <div style="width: 5rem; height: 5rem; background: #eff6ff; border-radius: 99px; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem auto;">
            {* Rocket Icon *}
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-rocket"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-5c1.62-2.2 5-3 5-3l3 1"/><path d="M12 15v5s3.03-.55 5-2c2.2-1.62 3-5 3-5l-1-3"/></svg>
        </div>
        <h3 class="ps-text-2xl ps-font-semibold ps-text-slate-900" style="margin-bottom: 0.5rem;">{l s='Ready to Migrate?' mod='prestashift'}</h3>
        <p class="ps-text-slate-600" style="margin-bottom: 1.5rem;">{l s='The process will start immediately. Please do not close this tab.' mod='prestashift'}</p>

        {* Dry Run Preview *}
        <div id="preview-container" style="margin-bottom: 1.5rem;">
            <div class="ps-card ps-p-4" style="text-align:left; background:#f8fafc;">
                <div class="ps-flex-start ps-gap-2" style="margin-bottom:8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <span class="ps-font-medium ps-text-slate-700">{l s='Migration Preview' mod='prestashift'}</span>
                </div>
                <div id="preview-data" style="color:#64748b; font-size:0.875rem;">
                    <div class="ps-text-center" style="padding:12px;"><i class="icon-spinner icon-spin"></i> {l s='Counting records...' mod='prestashift'}</div>
                </div>
            </div>
        </div>

        <div class="ps-flex-center" style="display: flex; justify-content: center; gap: 1rem; margin-top: 1rem;">
            <button type="button" class="ps-btn ps-btn-outline ps-btn-lg" onclick="PrestaShift.loadStep(3)">
                {l s='Back' mod='prestashift'}
            </button>
            <button type="button" class="ps-btn ps-btn-primary ps-btn-lg" id="btn-start-migration" style="min-width: 200px;">
                {l s='Launch Migration' mod='prestashift'}
            </button>
        </div>
    </div>
</div>

<div id="migration-progress-container" style="display: none;" class="ps-space-y-6">
    <div class="ps-text-center ps-space-y-2">
        <h2 class="ps-text-2xl ps-font-semibold ps-text-slate-900">{l s='Migration in Progress' mod='prestashift'}</h2>
        <p class="ps-text-slate-600">{l s='Please wait while we transfer your data' mod='prestashift'}</p>
    </div>

    <div class="ps-card ps-p-8 ps-space-y-6">
        <div class="ps-space-y-4">
             <div class="ps-flex-between">
                <span class="ps-text-sm ps-font-medium ps-text-slate-700">{l s='Overall Progress' mod='prestashift'}</span>
                <span class="ps-text-2xl ps-font-bold ps-text-blue-600" id="main-progress-text">0%</span>
            </div>
            {* New Progress Bar System *}
            <div class="ps-progress-track">
                <div id="main-progress-bar" class="ps-progress-fill" style="width: 0%;"></div>
            </div>
            <p class="ps-text-sm ps-text-slate-600 ps-text-center ps-font-medium" id="current-action" style="margin-top: 1rem;">
                {l s='Initializing session...' mod='prestashift'}
            </p>
        </div>

        {* Terminal Style Log *}
        <div class="ps-space-y-3">
            <div class="ps-flex-start ps-gap-2 ps-text-slate-600">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-terminal"><polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/></svg>
                <span class="ps-text-sm ps-font-medium">{l s='Migration Log' mod='prestashift'}</span>
            </div>
            <div id="migration-log-container" class="ps-terminal">
                <div id="migration-log">
                    <div class="ps-terminal-line">
                         <span class="ps-terminal-time">[{date('H:i:s')}]</span>
                         <span class="ps-terminal-msg">Ready to start.</span>
                    </div>
                </div>
            </div>
        </div>

        {* Action Bar for Pause/Resume *}
        <div class="ps-flex-between" style="border-top: 1px solid #e2e8f0; padding-top: 2rem; margin-top: 1.5rem;">
            <div id="finish-later-area" style="visibility: hidden;">
                <button type="button" class="ps-btn ps-btn-outline" onclick="PrestaShift.finishLater()">
                    {l s='Finish Later' mod='prestashift'}
                </button>
            </div>
            <div id="migration-actions">
                <button type="button" class="ps-btn ps-btn-primary" id="btn-pause-migration" onclick="PrestaShift.pauseMigration()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pause" style="margin-right: 8px;"><rect width="4" height="16" x="6" y="4" rx="1"/><rect width="4" height="16" x="14" y="4" rx="1"/></svg>
                    {l s='Pause Migration' mod='prestashift'}
                </button>
            </div>
        </div>
    </div>
</div>

<div id="migration-finished-container" style="display: none;" class="ps-space-y-6">
    <div class="ps-text-center ps-space-y-4">
        <div style="display: flex; justify-content: center;">
             <div style="width: 5rem; height: 5rem; background-color: #dcfce7; border-radius: 99px; display: flex; align-items: center; justify-content: center;">
                {* Check Icon *}
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check-circle"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
        </div>
        <h2 class="ps-text-2xl ps-font-semibold ps-text-slate-900">{l s='Migration Completed!' mod='prestashift'}</h2>
        <p class="ps-text-slate-600 ps-text-lg">{l s='Your data has been successfully transferred to the new shop' mod='prestashift'}</p>
    </div>

    <div class="ps-card ps-p-0 ps-overflow-hidden ps-border-slate-200">
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); text-align: center;">
            <div style="padding: 2.5rem 1.5rem; border-right: 1px solid #e2e8f0;">
                <p id="stat-products" class="ps-text-3xl ps-font-bold ps-text-blue-600" style="margin-bottom: 0.5rem;">0</p>
                <p class="ps-text-sm ps-text-slate-500 ps-font-medium">{l s='Products Migrated' mod='prestashift'}</p>
            </div>
            <div style="padding: 2.5rem 1.5rem; border-right: 1px solid #e2e8f0;">
                <p id="stat-orders" class="ps-text-3xl ps-font-bold ps-text-purple-600" style="margin-bottom: 0.5rem;">0</p>
                <p class="ps-text-sm ps-text-slate-500 ps-font-medium">{l s='Orders Transferred' mod='prestashift'}</p>
            </div>
            <div style="padding: 2.5rem 1.5rem;">
                <p id="stat-customers" class="ps-text-3xl ps-font-bold ps-text-purple-600" style="margin-bottom: 0.5rem;">0</p>
                <p class="ps-text-sm ps-text-slate-500 ps-font-medium">{l s='Customers Synced' mod='prestashift'}</p>
            </div>
        </div>
    </div>

    <div class="ps-card ps-p-8 ps-bg-blue-50 ps-border-blue-100" style="border-radius: 12px;">
        <div class="ps-text-center ps-space-y-4">
            <div style="display: flex; justify-content: center; margin-bottom: 1rem;">
                <div style="color: #ef4444; background: #fff; width: 40px; height: 40px; border-radius: 99px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-heart"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                </div>
            </div>
            <h3 class="ps-text-xl ps-font-semibold ps-text-slate-900">{l s='Support the Project' mod='prestashift'}</h3>
            <p class="ps-text-slate-600" style="max-width: 500px; margin: 0 auto; font-size: 0.9375rem;">
                {l s='If you find PrestaShift useful, consider supporting development' mod='prestashift'}
            </p>
            <div class="ps-flex-center" style="display: flex; flex-wrap: wrap; justify-content: center; gap: 1rem; margin-top: 1.5rem;">
                <a href="https://buymeacoffee.com/marcingajewski" target="_blank" class="ps-btn ps-btn-lg" style="padding-left: 2rem; padding-right: 2rem; background: #FFDD00; color: #000; border: none; font-weight: 600;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" x2="6" y1="2" y2="4"/><line x1="10" x2="10" y1="2" y2="4"/><line x1="14" x2="14" y1="2" y2="4"/></svg>
                    {l s='Buy me a coffee' mod='prestashift'}
                </a>
                <a href="https://github.com/GajewskiMarcin/prestashift" target="_blank" class="ps-btn ps-btn-outline ps-btn-lg" style="padding-left: 2rem; padding-right: 2rem;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    {l s='Star on GitHub' mod='prestashift'}
                </a>
            </div>
            <div style="margin-top: 1rem;">
                <a href="https://github.com/GajewskiMarcin/prestashift/discussions" target="_blank" style="color: #64748b; font-size: 0.875rem; text-decoration: underline;">
                    {l s='Questions? Visit Discussions' mod='prestashift'}
                </a>
            </div>
        </div>
    </div>

    <div class="ps-flex-center" style="display: flex; justify-content: center;">
        <a href="{$controller_url|escape:'html':'UTF-8'}" class="ps-btn ps-btn-outline ps-btn-lg">
            {l s='Start New Migration' mod='prestashift'}
        </a>
    </div>
</div>
