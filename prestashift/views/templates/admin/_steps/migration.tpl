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
        <div id="final-stats-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); text-align: center;">
            {* Populated dynamically by admin.js based on selected scopes *}
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
                {* Attribution Notice (AFL-3.0 section 6): derivative works must keep this block. *}
                <a href="https://ko-fi.com/quinceproject" target="_blank" class="ps-btn ps-btn-lg" style="padding-left: 2rem; padding-right: 2rem; background: #FFDD00; color: #000; border: none; font-weight: 600;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" x2="6" y1="2" y2="4"/><line x1="10" x2="10" y1="2" y2="4"/><line x1="14" x2="14" y1="2" y2="4"/></svg>
                    {l s='Support on Ko-fi' mod='prestashift'}
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

    {* Quince Project: the author's theme and module suite (views/css/quince-promo.css) *}
    <section class="quince-promo" style="margin-top: 0;">
        <div class="quince-promo-wash" aria-hidden="true"></div>
        <i class="quince-promo-beam b1" aria-hidden="true"></i><i class="quince-promo-beam b2" aria-hidden="true"></i><i class="quince-promo-beam b3" aria-hidden="true"></i><i class="quince-promo-beam b4" aria-hidden="true"></i>
        <div class="quince-promo-in">
            <p class="quince-promo-lead">{l s='Discover the theme and engine for PrestaShop' mod='prestashift'}</p>
            <p class="quince-promo-word">Quince Project</p>
            <p class="quince-promo-text">{l s='A set of over 20 modules and a theme that let you freely manage the look and content of your shop and support back-office processes.' mod='prestashift'}</p>
            <div class="quince-promo-cta">
                <a class="quince-promo-btn" href="https://github.com/quinceproject" target="_blank" rel="noopener">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 0c4.42 0 8 3.58 8 8a8.013 8.013 0 0 1-5.45 7.59c-.4.08-.55-.17-.55-.38 0-.27.01-1.13.01-2.2 0-.75-.25-1.23-.54-1.48 1.78-.2 3.65-.88 3.65-3.95 0-.88-.31-1.59-.82-2.15.08-.2.36-1.02-.08-2.12 0 0-.67-.22-2.2.82-.64-.18-1.32-.27-2-.27-.68 0-1.36.09-2 .27-1.53-1.03-2.2-.82-2.2-.82-.44 1.1-.16 1.92-.08 2.12-.51.56-.82 1.28-.82 2.15 0 3.06 1.86 3.75 3.64 3.95-.23.2-.44.55-.51 1.07-.46.21-1.61.55-2.33-.66-.15-.24-.6-.83-1.23-.82-.67.01-.27.38.01.53.34.19.73.9.82 1.13.16.45.68 1.31 2.69.94 0 .67.01 1.3.01 1.49 0 .21-.15.45-.55.38A7.995 7.995 0 0 1 0 8c0-4.42 3.58-8 8-8Z"/></svg>
                    {l s='Download for free from GitHub' mod='prestashift'}
                </a>
            </div>
            <div class="quince-promo-facts">
                <div><b>PrestaShop 9.2</b></div>
                <div><b>{l s='PHP 8.1 or newer' mod='prestashift'}</b></div>
                <div><b>{l s='OSL-3.0 licence' mod='prestashift'}</b></div>
            </div>
        </div>
    </section>

    <div class="ps-flex-center" style="display: flex; justify-content: center;">
        <a href="{$controller_url|escape:'html':'UTF-8'}" class="ps-btn ps-btn-outline ps-btn-lg">
            {l s='Start New Migration' mod='prestashift'}
        </a>
    </div>
</div>
