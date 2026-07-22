{* Step 2: Scope - Data Selection with Dependencies *}
<div class="ps-space-y-6">

    <div class="ps-text-center ps-space-y-2">
        <h2 class="ps-text-2xl ps-font-semibold ps-text-slate-900">{l s='Select Data to Migrate' mod='prestashift'}</h2>
        <p class="ps-text-slate-600">{l s='Choose which data you want to transfer to your new shop' mod='prestashift'}</p>
    </div>

    <div class="ps-card ps-p-6 ps-space-y-6">
        <form id="scope-form">

            {* GROUP 1: CATALOG *}
            <div class="ps-space-y-4">
                <div class="ps-flex-start ps-gap-2" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 0.5rem; align-items: baseline !important;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: relative; top: 2px;"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                    <h3 class="ps-font-semibold ps-text-slate-900 ps-text-lg">{l s='Catalog' mod='prestashift'}</h3>
                </div>
                <div style="padding-left: 1.5rem;" class="ps-grid-2">
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[catalog]" value="1" checked class="ps-checkbox-input">
                        <div>
                            <span class="ps-font-medium ps-text-slate-900">{l s='Products & Categories' mod='prestashift'}</span>
                            <p class="ps-text-xs ps-text-slate-500">{l s='Attributes, Features, Stock, Packs, Virtual Products, Customization' mod='prestashift'}</p>
                        </div>
                    </label>
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[images]" value="1" checked class="ps-checkbox-input">
                         <div>
                            <span class="ps-font-medium ps-text-slate-900">{l s='Product Images' mod='prestashift'}</span>
                            <p class="ps-text-xs ps-text-slate-500">{l s='With thumbnail generation' mod='prestashift'}</p>
                        </div>
                    </label>
                     <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[manufacturers]" value="1" checked class="ps-checkbox-input">
                         <div>
                            <span class="ps-font-medium ps-text-slate-900">{l s='Manufacturers' mod='prestashift'}</span>
                            <p class="ps-text-xs ps-text-slate-500">{l s='Brands & logos' mod='prestashift'}</p>
                        </div>
                    </label>
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[suppliers]" value="1" checked class="ps-checkbox-input">
                        <div>
                            <span class="ps-font-medium ps-text-slate-900">{l s='Suppliers' mod='prestashift'}</span>
                            <p class="ps-text-xs ps-text-slate-500">{l s='Including product-supplier prices' mod='prestashift'}</p>
                        </div>
                    </label>
                     <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[attachments]" value="1" checked class="ps-checkbox-input">
                        <span class="ps-font-medium ps-text-slate-900">{l s='Attachments' mod='prestashift'}</span>
                    </label>
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[specific_prices]" value="1" checked class="ps-checkbox-input">
                        <div>
                            <span class="ps-font-medium ps-text-slate-900">{l s='Pricing Rules' mod='prestashift'}</span>
                            <p class="ps-text-xs ps-text-slate-500">{l s='Specific Prices & Catalog Price Rules' mod='prestashift'}</p>
                        </div>
                    </label>
                </div>
            </div>

            {* GROUP 2: CUSTOMERS & ORDERS *}
            <div class="ps-space-y-4" style="margin-top: 2rem;">
                <div class="ps-flex-start ps-gap-2" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 0.5rem; align-items: baseline !important;">
                     <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: relative; top: 2px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <h3 class="ps-font-semibold ps-text-slate-900 ps-text-lg">{l s='Customers & Orders' mod='prestashift'}</h3>
                </div>
                <div style="padding-left: 1.5rem;" class="ps-grid-2">
                     <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[customers]" value="1" checked class="ps-checkbox-input" data-dependency-for="orders,messages">
                        <div>
                            <span class="ps-font-medium ps-text-slate-900">{l s='Customers' mod='prestashift'}</span>
                            <p class="ps-text-xs ps-text-slate-500">{l s='Accounts, Groups, Addresses' mod='prestashift'}</p>
                        </div>
                    </label>
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[orders]" value="1" checked class="ps-checkbox-input" data-requires="customers">
                         <div>
                            <span class="ps-font-medium ps-text-slate-900">{l s='Orders' mod='prestashift'}</span>
                            <p class="ps-text-xs ps-text-slate-500">{l s='History, Payments, Invoices, Credit Slips, Carts' mod='prestashift'}</p>
                        </div>
                    </label>
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[messages]" value="1" checked class="ps-checkbox-input" data-requires="customers">
                        <span class="ps-font-medium ps-text-slate-900">{l s='Customer Messages' mod='prestashift'}</span>
                    </label>
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[cart_rules]" value="1" checked class="ps-checkbox-input">
                        <div>
                            <span class="ps-font-medium ps-text-slate-900">{l s='Vouchers (Cart Rules)' mod='prestashift'}</span>
                            <p class="ps-text-xs ps-text-slate-500">{l s='Discounts & promo codes' mod='prestashift'}</p>
                        </div>
                    </label>
                </div>
            </div>

            {* GROUP 3: LOCATION & LOGISTICS *}
            <div class="ps-space-y-4" style="margin-top: 2rem;">
                <div class="ps-flex-start ps-gap-2" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 0.5rem; align-items: baseline !important;">
                     <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: relative; top: 2px;"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" x2="9" y1="3" y2="18"/><line x1="15" x2="15" y1="6" y2="21"/></svg>
                    <h3 class="ps-font-semibold ps-text-slate-900 ps-text-lg">{l s='Location & Logistics' mod='prestashift'}</h3>
                </div>
                <div style="padding-left: 1.5rem;" class="ps-grid-2">
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[localization]" value="1" checked class="ps-checkbox-input">
                        <div>
                             <span class="ps-font-medium ps-text-slate-900">{l s='Localization' mod='prestashift'}</span>
                             <p class="ps-text-xs ps-text-slate-500">{l s='Currencies, Languages, Countries, Zones, States/Regions' mod='prestashift'}</p>
                        </div>
                    </label>
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[tax_rules]" value="1" checked class="ps-checkbox-input">
                        <span class="ps-font-medium ps-text-slate-900">{l s='Tax Rules' mod='prestashift'}</span>
                    </label>
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[carriers]" value="1" checked class="ps-checkbox-input">
                        <div>
                            <span class="ps-font-medium ps-text-slate-900">{l s='Carriers' mod='prestashift'}</span>
                             <p class="ps-text-xs ps-text-slate-500">{l s='Ranges, Fees & Zones' mod='prestashift'}</p>
                        </div>
                    </label>
                </div>
            </div>

            {* GROUP 4: CONTENT *}
            <div class="ps-space-y-4" style="margin-top: 2rem;">
                <div class="ps-flex-start ps-gap-2" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 0.5rem; align-items: baseline !important;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: relative; top: 2px;"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    <h3 class="ps-font-semibold ps-text-slate-900 ps-text-lg">{l s='Content' mod='prestashift'}</h3>
                </div>
                <div style="padding-left: 1.5rem;" class="ps-grid-2">
                     <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[cms]" value="1" checked class="ps-checkbox-input">
                        <div>
                             <span class="ps-font-medium ps-text-slate-900">{l s='CMS Pages' mod='prestashift'}</span>
                             <p class="ps-text-xs ps-text-slate-500">{l s='Static pages, categories & SEO meta' mod='prestashift'}</p>
                        </div>
                    </label>
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[contacts]" value="1" checked class="ps-checkbox-input">
                        <div>
                             <span class="ps-font-medium ps-text-slate-900">{l s='Contacts & Stores' mod='prestashift'}</span>
                             <p class="ps-text-xs ps-text-slate-500">{l s='Contact forms & physical store locations' mod='prestashift'}</p>
                        </div>
                    </label>
                </div>
            </div>

            {* GROUP 5: ADMINISTRATION *}
            <div class="ps-space-y-4" style="margin-top: 2rem;">
                <div class="ps-flex-start ps-gap-2" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 0.5rem; align-items: baseline !important;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: relative; top: 2px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <h3 class="ps-font-semibold ps-text-slate-900 ps-text-lg">{l s='Administration' mod='prestashift'}</h3>
                </div>
                 <div style="padding-left: 1.5rem;" class="ps-grid-2">
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[employees]" value="1" checked class="ps-checkbox-input">
                        <div>
                            <span class="ps-font-medium ps-text-slate-900">{l s='Employees' mod='prestashift'}</span>
                            <p class="ps-text-xs ps-text-slate-500">{l s='Profiles & permissions' mod='prestashift'}</p>
                        </div>
                    </label>
                    <label class="ps-checkbox-wrapper">
                        <input type="checkbox" name="scope[configuration]" value="1" checked class="ps-checkbox-input">
                        <div>
                            <span class="ps-font-medium ps-text-slate-900">{l s='Shop Settings' mod='prestashift'}</span>
                            <p class="ps-text-xs ps-text-slate-500">{l s='Selected configuration values (name, SEO, shipping, etc.)' mod='prestashift'}</p>
                        </div>
                    </label>
                </div>
            </div>

        </form>
    </div>

    <div class="ps-flex-between">
        <button type="button" class="ps-btn ps-btn-outline ps-btn-lg" onclick="PrestaShift.loadStep(1)">
            {l s='Back' mod='prestashift'}
        </button>
        <button type="button" class="ps-btn ps-btn-primary ps-btn-lg" id="btn-submit-scope">
            {l s='Next: Options' mod='prestashift'}
        </button>
    </div>

</div>
