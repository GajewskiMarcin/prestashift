{* Admin Template for PsConnector - Modern UI (V3 - Clean Overhead Header) *}
<div id="psconnector-app" style="max-width: 900px; margin: 0 auto; padding: 20px;">
    
    {* HEADER ABOVE PANEL *}
    <div class="psc-header ps-text-center" style="margin-bottom: 4rem; margin-top: 3rem; text-align: center;">
        <div style="display: flex; align-items: center; justify-content: center; gap: 0.75rem; margin-bottom: 0.75rem;">
            <div style="background-color: #3b82f6; border-radius: 8px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="#fff" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            </div>
            <h2 style="margin: 0; font-size: 1.75rem; font-weight: 600; line-height: 1.2; color: #0f172a;">{l s='PrestaShift Connector' mod='psconnector'}</h2>
        </div>
        <p style="color: #64748b; font-size: 1rem; text-align: center;">{l s='Secure API endpoint for PrestaShift migration tool' mod='psconnector'}</p>
    </div>

    {* MAIN PANEL *}
    <div class="panel psc-main-panel">
        <form action="{$currentIndex|escape:'html':'UTF-8'}&token={$token|escape:'html':'UTF-8'}" method="post" class="psc-main-form">
            
            <div class="psc-card">
                <div class="psc-alert psc-alert-info" style="margin-bottom: 2.5rem;">
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <span style="font-size: 0.95rem;">{l s='API Endpoint URL: ' mod='psconnector'} <strong>{$api_url}</strong></span>
                    </div>
                </div>

                <div class="psc-form-group">
                    <label class="psc-label" style="display: block; font-weight: 600; margin-bottom: 0.75rem; color: #334155;">{l s='Security Token' mod='psconnector'}</label>
                <div class="psc-input-group" style="display: flex; gap: 12px; margin-bottom: 1rem;">
                    <input type="text" name="PS_CONNECTOR_TOKEN" value="{$connector_token|escape:'html':'UTF-8'}" class="psc-input" id="ps-token-input" style="flex: 1; height: 44px; padding: 0 16px; border-radius: 6px; border: 1px solid #e2e8f0; background: #f3f3f5; font-family: 'JetBrains Mono', monospace; font-size: 14px;">
                    <button type="button" class="psc-btn psc-btn-outline" onclick="copyToken()" style="height: 44px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        {l s='Copy' mod='psconnector'}
                    </button>
                </div>
                    <p style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 2.5rem;">
                        {l s='Copy this token to your PrestaShift module in the target shop to authorize the connection.' mod='psconnector'}
                    </p>
                </div>

                <div style="display: flex; justify-content: flex-end; align-items: center; border-top: 1px solid #f1f5f9; padding-top: 2rem; margin-top: 1rem; gap: 1.5rem;">
                    <p style="font-size: 0.85rem; color: #64748b; margin: 0; flex: 1;">{l s='Token is saved automatically when generated.' mod='psconnector'}</p>
                    <button type="submit" name="resetToken" class="psc-btn psc-btn-primary" onclick="return confirm('{l s='Are you sure you want to generate a new token? This will break any existing connections.' mod='psconnector'}')" style="height: 44px; padding: 0 32px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                        {l s='Generate New Token' mod='psconnector'}
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="psc-footer" style="margin-top: 3rem; text-align: center; font-size: 0.85rem; color: #94a3b8;">
        {l s='Powered by' mod='psconnector'} <a href="https://marcingajewski.pl" target="_blank" style="color: #2563eb; text-decoration: none; font-weight: 600;">marcingajewski.pl</a> | v{$module_version}
    </div>
</div>

<script>
function copyToken() {
    var copyText = document.getElementById("ps-token-input");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    document.execCommand("copy");
    alert("{l s='Token copied to clipboard!' mod='psconnector'}");
}
</script>
