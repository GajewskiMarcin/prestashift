<div class="ps-space-y-6">

    <div id="resume-session-area" style="display: none; margin-bottom: 2rem;"></div>

    <div class="ps-text-center ps-space-y-2">
        <h2 class="ps-text-2xl ps-font-semibold ps-text-slate-900">{l s='Connect to Source Shop' mod='prestashift'}</h2>
        <p class="ps-text-slate-600">{l s='Enter your source shop credentials to begin the migration' mod='prestashift'}</p>
    </div>

    {* Connection Method Toggle *}
    <div class="ps-card ps-p-4">
        <div class="ps-flex-start ps-gap-4" style="justify-content: center;">
            <label class="ps-checkbox-wrapper" style="cursor:pointer; padding: 12px 24px; border:2px solid #3b82f6; border-radius:8px; background:#eff6ff;">
                <input type="radio" name="connection_method" value="bridge" checked style="margin-right:8px;" onchange="PrestaShift.toggleConnectionMethod()">
                <div>
                    <span class="ps-font-medium ps-text-slate-900">{l s='Bridge Connector' mod='prestashift'}</span>
                    <p class="ps-text-xs ps-text-slate-500">{l s='Recommended — works across servers' mod='prestashift'}</p>
                </div>
            </label>
            <label class="ps-checkbox-wrapper" style="cursor:pointer; padding: 12px 24px; border:2px solid #e2e8f0; border-radius:8px;">
                <input type="radio" name="connection_method" value="direct" style="margin-right:8px;" onchange="PrestaShift.toggleConnectionMethod()">
                <div>
                    <span class="ps-font-medium ps-text-slate-900">{l s='Direct Database' mod='prestashift'}</span>
                    <p class="ps-text-xs ps-text-slate-500">{l s='Faster — requires DB access' mod='prestashift'}</p>
                </div>
            </label>
        </div>
    </div>

    {* Bridge Instructions *}
    <div id="bridge-info" class="ps-card ps-p-6 ps-bg-blue-50 ps-border-blue-200">
        <div class="ps-flex-start ps-gap-3">
             <i class="icon-info-circle ps-text-blue-600" style="font-size: 1.25rem;"></i>
             <div class="ps-space-y-1">
                 <p class="ps-font-medium ps-text-blue-900" style="font-size: 0.875rem;">{l s='Setup Instructions:' mod='prestashift'}</p>
                 <ol style="list-style-type: decimal; list-style-position: inside; color: #1e40af; font-size: 0.875rem;">
                     <li>{l s='Install Connector module on source shop' mod='prestashift'}</li>
                     <li>{l s='Copy the secure token' mod='prestashift'}</li>
                 </ol>
             </div>
        </div>
    </div>

    {* Direct DB Info *}
    <div id="direct-info" class="ps-card ps-p-6 ps-bg-blue-50 ps-border-blue-200" style="display:none;">
        <div class="ps-flex-start ps-gap-3">
             <i class="icon-info-circle ps-text-blue-600" style="font-size: 1.25rem;"></i>
             <div class="ps-space-y-1">
                 <p class="ps-font-medium ps-text-blue-900" style="font-size: 0.875rem;">{l s='Direct Database Connection' mod='prestashift'}</p>
                 <p style="color: #1e40af; font-size: 0.875rem;">{l s='Enter the database credentials of your source shop. Remote MySQL access must be enabled.' mod='prestashift'}</p>
             </div>
        </div>
    </div>

    {* Bridge Form *}
    <div id="bridge-form-container" class="ps-card ps-p-8 ps-space-y-6">
        <form id="connection-form" autocomplete="off">
            <input type="hidden" name="connection_method" value="bridge">

            <div class="ps-space-y-2">
                <label class="ps-font-medium ps-text-slate-700" style="display: flex; align-items: center; gap: 0.5rem;">
                    <i class="icon-globe"></i> {l s='Source Shop URL' mod='prestashift'}
                </label>
                <input type="url" name="source_url" class="ps-input" placeholder="https://myshop.com" required autocomplete="off">
            </div>

            <div class="ps-space-y-2" style="margin-top: 1.5rem;">
                <label class="ps-font-medium ps-text-slate-700" style="display: flex; align-items: center; gap: 0.5rem;">
                    <i class="icon-key"></i> {l s='Connector Token' mod='prestashift'}
                </label>
                <input type="password" name="bridge_token" class="ps-input" placeholder="Enter secure token" required autocomplete="new-password">
            </div>

            <div class="ps-space-y-2" style="margin-top: 1.5rem;">
                <label class="ps-font-medium ps-text-slate-700" style="display: flex; align-items: center; gap: 0.5rem;">
                    <i class="icon-database"></i> {l s='Table Prefix' mod='prestashift'}
                </label>
                <input type="text" name="db_prefix" class="ps-input" value="ps_" style="max-width: 120px;">
                <p class="ps-text-xs ps-text-slate-500" style="margin-top: 0.25rem;">{l s='Default database table prefix' mod='prestashift'}</p>
            </div>
        </form>
    </div>

    {* Direct DB Form *}
    <div id="direct-form-container" class="ps-card ps-p-8 ps-space-y-6" style="display:none;">
        <form id="direct-connection-form" autocomplete="off">
            <input type="hidden" name="connection_method" value="direct">

            <div class="ps-grid-2">
                <div class="ps-space-y-2">
                    <label class="ps-font-medium ps-text-slate-700">{l s='Database Host' mod='prestashift'}</label>
                    <input type="text" name="db_host" class="ps-input" placeholder="localhost" required autocomplete="off">
                </div>
                <div class="ps-space-y-2">
                    <label class="ps-font-medium ps-text-slate-700">{l s='Port' mod='prestashift'}</label>
                    <input type="number" name="db_port" class="ps-input" value="3306" style="max-width:120px;">
                </div>
            </div>

            <div class="ps-space-y-2" style="margin-top: 1.5rem;">
                <label class="ps-font-medium ps-text-slate-700">{l s='Database Name' mod='prestashift'}</label>
                <input type="text" name="db_name" class="ps-input" placeholder="prestashop_old" required autocomplete="off">
            </div>

            <div class="ps-grid-2" style="margin-top: 1.5rem;">
                <div class="ps-space-y-2">
                    <label class="ps-font-medium ps-text-slate-700">{l s='Database User' mod='prestashift'}</label>
                    <input type="text" name="db_user" class="ps-input" placeholder="root" required autocomplete="off">
                </div>
                <div class="ps-space-y-2">
                    <label class="ps-font-medium ps-text-slate-700">{l s='Database Password' mod='prestashift'}</label>
                    <input type="password" name="db_pass" class="ps-input" autocomplete="new-password">
                </div>
            </div>

            <div class="ps-grid-2" style="margin-top: 1.5rem;">
                <div class="ps-space-y-2">
                    <label class="ps-font-medium ps-text-slate-700">{l s='Table Prefix' mod='prestashift'}</label>
                    <input type="text" name="db_prefix" class="ps-input" value="ps_" style="max-width: 120px;">
                </div>
                <div class="ps-space-y-2">
                    <label class="ps-font-medium ps-text-slate-700">{l s='Source Shop URL' mod='prestashift'} <span class="ps-text-xs ps-text-slate-400">({l s='optional, for images' mod='prestashift'})</span></label>
                    <input type="url" name="source_url" class="ps-input" placeholder="https://myshop.com" autocomplete="off">
                </div>
            </div>
        </form>
    </div>

    <div id="connection-warnings"></div>

    <div style="display: flex; justify-content: flex-end;">
        <button type="button" class="ps-btn ps-btn-primary ps-btn-lg" id="btn-check-connection" onclick="PrestaShift.checkConnection(event)">
            {l s='Test Connection & Continue' mod='prestashift'}
        </button>
    </div>

</div>
