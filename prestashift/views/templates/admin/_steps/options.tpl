{* Step 3: Options - V7 Source Match (Switches + Lucide Icons) *}
<div class="ps-space-y-6">

    <div class="ps-text-center ps-space-y-2">
        <h2 class="ps-text-2xl ps-font-semibold ps-text-slate-900">{l s='Advanced Configuration' mod='prestashift'}</h2>
        <p class="ps-text-slate-600">{l s='Fine-tune your migration settings' mod='prestashift'}</p>
    </div>

    <form id="options-form">
        
        {* Main Options Card *}
        <div class="ps-card ps-p-6 ps-space-y-6">
            <div class="ps-flex-start ps-gap-3" style="padding-bottom: 0.5rem; align-items: center !important;">
                <div style="background: #f1f5f9; width: 2.5rem; height: 2.5rem; display:flex; align-items:center; justify-content:center; border-radius: 8px;">
                     {* Settings Icon *}
                     <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-settings"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.1a2 2 0 0 1-1-1.72v-.51a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                </div>
                <h3 class="ps-font-semibold ps-text-slate-900">{l s='General Options' mod='prestashift'}</h3>
            </div>

            <div class="ps-space-y-4">
                {* Clean Target Data - WARNING *}
                <div class="ps-card ps-p-4 ps-bg-red-50 ps-border-red-200" style="margin-bottom: 0;">
                    <div class="ps-flex-between">
                        <div class="ps-flex-start ps-gap-3">
                            <div style="padding-top: 4px;">
                                {* AlertTriangle Icon *}
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-alert-triangle"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                            </div>
                            <div>
                                <label class="ps-font-medium ps-text-slate-900" style="color: #7f1d1d; cursor: pointer;" for="opt-clean-target">{l s='Clean Target Data' mod='prestashift'}</label>
                                <p class="ps-text-sm" style="color: #b91c1c;">{l s='REQUIRED for ID Preservation. Deletes existing data!' mod='prestashift'}</p>
                            </div>
                        </div>
                        <label class="ps-switch-label">
                            <input type="checkbox" name="options[clean_target]" id="opt-clean-target" value="1" class="ps-switch-input">
                            <span class="ps-switch-slider"></span>
                        </label>
                    </div>
                </div>

                {* Incremental Migration *}
                <div class="ps-option-row ps-flex-between">
                    <div>
                        <label class="ps-font-medium ps-text-slate-900">{l s='Incremental Migration (Delta)' mod='prestashift'}</label>
                        <p class="ps-text-sm ps-text-slate-500">{l s='Only migrate new data since last sync' mod='prestashift'}</p>
                    </div>
                     <label class="ps-switch-label">
                        <input type="checkbox" name="options[incremental]" id="opt-incremental" value="1" class="ps-switch-input">
                        <span class="ps-switch-slider"></span>
                    </label>
                </div>

                {* Skip File Download *}
                <div class="ps-option-row ps-flex-between">
                    <div>
                        <label class="ps-font-medium ps-text-slate-900">{l s='Skip File Download' mod='prestashift'}</label>
                        <p class="ps-text-sm ps-text-slate-500">{l s='Manual Copy Mode (DB only)' mod='prestashift'}</p>
                    </div>
                     <label class="ps-switch-label">
                        <input type="checkbox" name="options[skip_files]" value="1" class="ps-switch-input">
                        <span class="ps-switch-slider"></span>
                    </label>
                </div>
                
                {* Force ID Preservation (Legacy but Critical) *}
                <div class="ps-option-row ps-flex-between">
                     <div>
                         <label class="ps-font-medium ps-text-slate-900">{l s='Force ID Preservation' mod='prestashift'}</label>
                         <p class="ps-text-sm ps-text-slate-500">{l s='Keep original product and category IDs' mod='prestashift'}</p>
                    </div>
                     <label class="ps-switch-label">
                        <input type="checkbox" name="options[force_ids]" value="1" checked class="ps-switch-input">
                        <span class="ps-switch-slider"></span>
                    </label>
                </div>

                {* Target Shop ID (Multistore) *}
                <div class="ps-option-row ps-flex-between">
                    <div>
                        <label class="ps-font-medium ps-text-slate-900">{l s='Target Shop ID' mod='prestashift'}</label>
                        <p class="ps-text-sm ps-text-slate-500">{l s='For multistore: choose which shop to import into' mod='prestashift'}</p>
                    </div>
                    <input type="number" name="options[target_shop_id]" value="1" min="1" class="ps-input" style="width: 60px !important; text-align: center;">
                </div>

                {* Debug Mode *}
                <div class="ps-option-row ps-flex-between">
                     <div>
                         <label class="ps-font-medium ps-text-slate-900">{l s='Debug Mode (Logs)' mod='prestashift'}</label>
                         <p class="ps-text-sm ps-text-slate-500">{l s='Enable detailed logging' mod='prestashift'}</p>
                    </div>
                     <label class="ps-switch-label">
                        <input type="checkbox" name="options[debug_mode]" value="1" class="ps-switch-input">
                        <span class="ps-switch-slider"></span>
                    </label>
                </div>

                {* Share Telemetry *}
                <div class="ps-option-row ps-flex-between">
                    <div>
                         <label class="ps-font-medium ps-text-slate-900">{l s='Share Anonymous Telemetry' mod='prestashift'}</label>
                         <p class="ps-text-sm ps-text-slate-500">{l s='Help improve the module' mod='prestashift'}</p>
                    </div>
                     <label class="ps-switch-label">
                        <input type="checkbox" name="options[telemetry]" value="1" checked class="ps-switch-input">
                        <span class="ps-switch-slider"></span>
                    </label>
                </div>

            </div>
        </div>

        {* Performance Settings *}
        <div class="ps-card ps-p-6 ps-space-y-6">
             <div class="ps-flex-start ps-gap-3" style="padding-bottom: 0.5rem; align-items: center !important;">
                <div style="background: #dbeafe; width: 2.5rem; height: 2.5rem; display:flex; align-items:center; justify-content:center; border-radius: 8px;">
                     {* Zap Icon *}
                     <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-zap"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                </div>
                <h3 class="ps-font-semibold ps-text-slate-900">{l s='Performance Settings' mod='prestashift'}</h3>
            </div>

            <div class="ps-grid-2">
                <div class="ps-space-y-2">
                    <label class="ps-text-slate-700">{l s='Batch Size' mod='prestashift'}</label>
                    <input type="number" name="options[batch_size]" value="100" class="ps-input">
                    <p class="ps-text-xs ps-text-slate-500">{l s='Number of items processed per batch' mod='prestashift'}</p>
                </div>
                <div class="ps-space-y-2">
                    <label class="ps-text-slate-700">{l s='Request Delay (ms)' mod='prestashift'}</label>
                    <input type="number" name="options[delay]" value="500" class="ps-input">
                    <p class="ps-text-xs ps-text-slate-500">{l s='Delay between API requests' mod='prestashift'}</p>
                </div>
            </div>

            {* Image Performance *}
            <div class="ps-border-t ps-border-slate-100 ps-my-4"></div>
            
            <div class="ps-grid-2">
                 <div class="ps-space-y-2">
                    <label class="ps-text-slate-700">{l s='Image Batch Size' mod='prestashift'}</label>
                    <input type="number" name="options[img_batch_size]" value="20" class="ps-input" min="1" max="100">
                    <p class="ps-text-xs ps-text-slate-500">{l s='Images per batch (Recommended: 20)' mod='prestashift'}</p>
                </div>
                <div class="ps-space-y-2">
                    <label class="ps-text-slate-700">{l s='Image Delay (ms)' mod='prestashift'}</label>
                    <input type="number" name="options[img_delay]" value="200" class="ps-input">
                     <p class="ps-text-xs ps-text-slate-500">{l s='Wait time between image downloads' mod='prestashift'}</p>
                </div>
            </div>
        </div>

         {* Status Mapping *}
         <div id="status-mapping-area" style="display: none;">
            <div class="ps-card ps-p-6 ps-space-y-6">
                 <div class="ps-flex-start ps-gap-3" style="padding-bottom: 0.5rem; align-items: center !important;">
                    <div style="background: #e0e7ff; width: 2.5rem; height: 2.5rem; display:flex; align-items:center; justify-content:center; border-radius: 8px;">
                         {* ArrowRightLeft Icon *}
                         <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-right-left"><path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/></svg>
                    </div>
                    <h3 class="ps-font-semibold ps-text-slate-900">{l s='Order Status Mapping' mod='prestashift'}</h3>
                </div>
                 
                 <div id="status-mapping-table-container">
                     <!-- Populated by admin.js -->
                 </div>
            </div>
         </div>

         {* Zone Mapping *}
         <div id="zone-mapping-area" style="display: none;">
            <div class="ps-card ps-p-6 ps-space-y-6">
                 <div class="ps-flex-start ps-gap-3" style="padding-bottom: 0.5rem; align-items: center !important;">
                    <div style="background: #dcfce7; width: 2.5rem; height: 2.5rem; display:flex; align-items:center; justify-content:center; border-radius: 8px;">
                         <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" x2="9" y1="3" y2="18"/><line x1="15" x2="15" y1="6" y2="21"/></svg>
                    </div>
                    <h3 class="ps-font-semibold ps-text-slate-900">{l s='Zone Mapping' mod='prestashift'}</h3>
                </div>
                <p class="ps-text-sm ps-text-slate-500">{l s='Map source zones to target zones for carrier delivery configuration' mod='prestashift'}</p>

                 <div id="zone-mapping-table-container">
                     <!-- Populated by admin.js -->
                 </div>
            </div>
         </div>

    </form>
    
    <div class="ps-flex-between">
        <button type="button" class="ps-btn ps-btn-outline ps-btn-lg" onclick="PrestaShift.loadStep(2)">
            {l s='Back' mod='prestashift'}
        </button>
        <button type="button" class="ps-btn ps-btn-primary ps-btn-lg" id="btn-submit-options">
            {l s='Start Migration' mod='prestashift'}
        </button>
    </div>

</div>
