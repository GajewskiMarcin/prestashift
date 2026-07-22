{* Admin Template for PrestaShift - V7 Source Match *}
<div id="prestashift-app">
    
    {* HEADER *}
    <div class="ps-text-center ps-space-y-2" style="margin-bottom: 3rem;">
        <div style="display: flex; align-items: center; justify-content: center; gap: 0.75rem; margin-bottom: 0.5rem;">
            <div style="background-color: #3b82f6; border-radius: 8px; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="#fff" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-zap"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            </div>
            <h2 class="ps-text-2xl ps-font-semibold ps-text-slate-900" style="margin: 0;">{l s='PrestaShift Migration' mod='prestashift'}</h2>
        </div>
        <p class="ps-text-slate-500">{l s='Professional data migration tool for your PrestaShop' mod='prestashift'}</p>
    </div>

    {* STEPPER (Matches Stepper.tsx) *}
    <div class="ps-stepper-container">
        <div class="ps-stepper-flex" id="migration-stepper">
            
            {* Step 1 *}
            <div class="ps-step-item" data-step="1">
                <div class="ps-step-content ps-step-active">
                    <div class="ps-step-circle">1</div>
                    <div class="ps-step-label">{l s='Connection' mod='prestashift'}</div>
                </div>
                <div class="ps-step-line"></div>
            </div>

            {* Step 2 *}
            <div class="ps-step-item" data-step="2">
                <div class="ps-step-content">
                    <div class="ps-step-circle">2</div>
                    <div class="ps-step-label">{l s='Scope' mod='prestashift'}</div>
                </div>
                <div class="ps-step-line"></div>
            </div>

            {* Step 3 *}
            <div class="ps-step-item" data-step="3">
                <div class="ps-step-content">
                    <div class="ps-step-circle">3</div>
                    <div class="ps-step-label">{l s='Options' mod='prestashift'}</div>
                </div>
                <div class="ps-step-line"></div>
            </div>

            {* Step 4 *}
            <div class="ps-step-item" data-step="4">
                <div class="ps-step-content">
                    <div class="ps-step-circle">4</div>
                    <div class="ps-step-label">{l s='Migration' mod='prestashift'}</div>
                </div>
            </div>

        </div>
    </div>

    {* CONTENT *}
    <div id="step-content">
        <div class="ps-text-center" style="padding: 60px 0;">
             <i class="icon-spinner icon-spin icon-3x ps-text-blue-600"></i>
             <p class="ps-text-slate-500" style="margin-top: 1rem;">{l s='Loading interface...' mod='prestashift'}</p>
        </div>
    </div>

</div>

<script type="text/javascript">
    var controller_url = "{$controller_url|escape:'javascript':'UTF-8'}";
    {if isset($ps_translations)}
        var ps_translations = {$ps_translations|json_encode};
    {/if}
</script>
