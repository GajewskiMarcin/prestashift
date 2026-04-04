/**
 * PrestaShift Admin Scripts - V7 Corrected
 */
var PrestaShift = {
    currentStep: 1,
    connectionData: null,
    scopeData: null,
    optionsData: null,
    isPaused: false,
    pendingNextBatch: null,
    stats: {
        products: 0,
        orders: 0,
        customers: 0
    },

    init: function () {
        this.loadStep(this.currentStep);
        this.bindEvents();
        if (this.currentStep === 1) {
            this.checkSavedState();
        }
    },

    t: function (key) {
        if (typeof ps_translations !== 'undefined' && ps_translations[key]) {
            return ps_translations[key];
        }
        return key;
    },

    bindEvents: function () {
        // NOTE: btn-check-connection is handled via onclick attribute

        $(document).on('click', '#btn-submit-scope', function () {
            PrestaShift.scopeData = $('#scope-form').serializeArray();
            PrestaShift.currentStep = 3;
            PrestaShift.loadStep(3);
        });

        // Scope dependency logic: orders & messages require customers
        $(document).on('change', '#scope-form input[type="checkbox"]', function () {
            PrestaShift.enforceScopeDependencies();
        });

        $(document).on('click', '#btn-submit-options', function () {
            PrestaShift.optionsData = $('#options-form').serializeArray();
            PrestaShift.currentStep = 4;
            PrestaShift.loadStep(4);
        });

        $(document).on('click', '#btn-start-migration', function () {
            PrestaShift.startMigration();
        });

        $(document).on('click', '#btn-resume-migration', function () {
            PrestaShift.resumeMigration();
        });
    },

    toggleConnectionMethod: function () {
        var method = $('input[name="connection_method"]:checked').val();
        if (method === 'direct') {
            $('#bridge-form-container, #bridge-info').hide();
            $('#direct-form-container, #direct-info').show();
            $('input[name="connection_method"]:checked').closest('label').css('border-color', '#3b82f6').css('background', '#eff6ff');
            $('input[name="connection_method"]:not(:checked)').closest('label').css('border-color', '#e2e8f0').css('background', '');
        } else {
            $('#bridge-form-container, #bridge-info').show();
            $('#direct-form-container, #direct-info').hide();
            $('input[name="connection_method"]:checked').closest('label').css('border-color', '#3b82f6').css('background', '#eff6ff');
            $('input[name="connection_method"]:not(:checked)').closest('label').css('border-color', '#e2e8f0').css('background', '');
        }
    },

    checkConnection: function (e) {
        if (e) e.preventDefault();

        var method = $('input[name="connection_method"]:checked').val() || 'bridge';
        var formId = (method === 'direct') ? '#direct-connection-form' : '#connection-form';
        var formDataStr = $(formId).serialize();
        var formDataArray = $(formId).serializeArray();

        // Validate required fields
        if (method === 'bridge') {
            var urlVal = $(formId + ' input[name="source_url"]').val();
            var tokenVal = $(formId + ' input[name="bridge_token"]').val();
            if (!urlVal || !tokenVal) {
                $.growl.error({ title: 'Error', message: 'Please fill in all fields' });
                return;
            }
        } else {
            var host = $(formId + ' input[name="db_host"]').val();
            var dbname = $(formId + ' input[name="db_name"]').val();
            var user = $(formId + ' input[name="db_user"]').val();
            if (!host || !dbname || !user) {
                $.growl.error({ title: 'Error', message: 'Please fill in host, database name, and user' });
                return;
            }
        }

        var $btn = $('#btn-check-connection');
        $btn.prop('disabled', true).html('<i class="icon-refresh icon-spin"></i> Testing...');

        $.ajax({
            url: controller_url,
            type: 'POST',
            data: formDataStr + '&ajax=true&action=check_connection',
            dataType: 'json',
            success: function (response) {
                $btn.prop('disabled', false).html('Test Connection & Continue'); // Restore Text

                if (response.success) {
                    PrestaShift.connectionData = formDataArray;
                    PrestaShift.sourceVersion = response.source_version || 'unknown';
                    PrestaShift.targetVersion = response.target_version || 'unknown';

                    var msg = response.message + ' | ' + response.source_version + ' → ' + response.target_version;
                    $.growl.notice({ title: 'Success', message: msg });

                    // Store warnings for display on step 2
                    PrestaShift.versionWarnings = response.warnings || [];
                    PrestaShift.sourceVersion = response.source_version || 'unknown';
                    PrestaShift.targetVersion = response.target_version || 'unknown';

                    // Move to Step 2
                    PrestaShift.currentStep = 2;
                    setTimeout(function () {
                        PrestaShift.loadStep(2);
                    }, 500);

                } else {
                    $.growl.error({ title: 'Error', message: response.message });
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                $btn.prop('disabled', false).html('Test Connection & Continue');
                $.growl.error({ title: 'Connection Error', message: textStatus + ': ' + errorThrown });
                console.error('Connection Check Failed:', jqXHR);
            }
        });
    },

    loadStep: function (step) {
        $('#step-content').html('<div class="ps-text-center" style="padding:40px;"><i class="icon-spinner icon-spin icon-3x ps-text-blue-600"></i><p class="ps-text-slate-500">' + PrestaShift.t('loading') + '</p></div>');

        // Update Stepper Visuals locally
        $('.ps-step-content').removeClass('ps-step-active ps-step-completed');
        $('.ps-step-line').removeClass('ps-step-line-active');

        $('.ps-step-item').each(function () {
            var s = $(this).data('step');
            if (s < step) {
                $(this).find('.ps-step-content').addClass('ps-step-completed');
                $(this).find('.ps-step-line').addClass('ps-step-line-active');
            } else if (s == step) {
                $(this).find('.ps-step-content').addClass('ps-step-active');
            }
        });

        $.ajax({
            url: controller_url,
            type: 'POST',
            data: {
                ajax: true,
                action: 'render_step',
                step: step
            },
            success: function (response) {
                if (response.content) {
                    $('#step-content').html(response.content);
                    PrestaShift.restoreData(step);

                    if (step === 2) {
                        PrestaShift.enforceScopeDependencies();
                        PrestaShift.showVersionWarnings();
                    }
                    if (step === 3) {
                        PrestaShift.loadStatusMapping();
                        PrestaShift.loadZoneMapping();
                        PrestaShift.bindStepEvents(3);
                    }
                    if (step === 4) {
                        PrestaShift.prepareSummary();
                        PrestaShift.loadPreview();
                    }

                } else {
                    $('#step-content').html('<div class="alert alert-danger">Empty content</div>');
                }
            },
            error: function () {
                $('#step-content').html('<div class="alert alert-danger">Failed to load step</div>');
            }
        });
    },

    restoreData: function (step) {
        if (step === 1 && PrestaShift.connectionData) {
            $.each(PrestaShift.connectionData, function (i, field) { $('[name="' + field.name + '"]').val(field.value); });
        }
        if (step === 2 && PrestaShift.scopeData) {
            $.each(PrestaShift.scopeData, function (i, field) {
                var $el = $('[name="' + field.name + '"]');
                if ($el.is(':checkbox')) $el.prop('checked', true);
                else $el.val(field.value);
            });
        }
        if (step === 3 && PrestaShift.optionsData) {
            $.each(PrestaShift.optionsData, function (i, field) {
                var $el = $('[name="' + field.name + '"]');
                if ($el.is(':checkbox')) $el.prop('checked', true);
                else $el.val(field.value);
            });
        }
    },

    bindStepEvents: function (step) {
        if (step === 3) {
            // Mutual exclusivity: Incremental vs Clean Target
            $(document).off('change', '#opt-incremental').on('change', '#opt-incremental', function () {
                if ($(this).is(':checked')) {
                    $('#opt-clean-target').prop('checked', false).trigger('change');
                }
            });
            $(document).off('change', '#opt-clean-target').on('change', '#opt-clean-target', function () {
                if ($(this).is(':checked')) {
                    $('#opt-incremental').prop('checked', false).trigger('change');
                }
            });
        }
    },

    prepareSummary: function () {
        var sourceUrl = PrestaShift.t('unknown');
        var cleanTarget = PrestaShift.t('no');

        if (PrestaShift.connectionData) {
            $.each(PrestaShift.connectionData, function (i, field) {
                if (field.name === 'source_url') sourceUrl = field.value;
            });
        }
        if (PrestaShift.optionsData) {
            $.each(PrestaShift.optionsData, function (i, field) {
                if (field.name === 'options[clean_target]' && field.value == '1') cleanTarget = PrestaShift.t('yes_clean');
            });
        }

        $('#summary-source').text(sourceUrl);
        $('#summary-clean').text(cleanTarget);

        // Simplified entities logic for now
        PrestaShift.checkSavedState();
    },

    loadZoneMapping: function () {
        var carriersEnabled = false;
        if (PrestaShift.scopeData) {
            $.each(PrestaShift.scopeData, function (i, field) {
                if (field.name === 'scope[carriers]' && field.value == '1') carriersEnabled = true;
            });
        }
        if (!carriersEnabled) { $('#zone-mapping-area').hide(); return; }

        $('#zone-mapping-area').show();
        var allData = [];
        if (PrestaShift.connectionData) allData = allData.concat(PrestaShift.connectionData);
        var formData = $.param(allData);

        $.ajax({
            url: controller_url,
            type: 'POST',
            data: formData + '&ajax=true&action=get_source_zones',
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    PrestaShift.renderZoneTable(response.source_zones, response.local_zones);
                } else {
                    $('#zone-mapping-table-container').html('<div class="alert alert-danger">' + (response.message || 'Error') + '</div>');
                }
            },
            error: function () {
                $('#zone-mapping-table-container').html('<div class="alert alert-danger">Error fetching zones</div>');
            }
        });
    },

    renderZoneTable: function (sourceZones, localZones) {
        var html = '<div class="ps-space-y-2">';
        $.each(sourceZones, function (i, source) {
            html += '<div class="ps-flex-between ps-p-3 ps-border ps-border-slate-200 ps-rounded-md ps-bg-slate-50">';

            html += '<div class="ps-flex-start ps-gap-2" style="flex: 1;">';
            html += '<span class="ps-font-medium ps-text-slate-700">' + source.name + '</span>';
            html += '<span class="ps-text-xs ps-text-slate-400">(ID: ' + source.id_zone + ')</span>';
            html += '</div>';

            html += '<div class="ps-text-slate-400" style="padding: 0 24px;"><i class="icon-long-arrow-right"></i></div>';

            html += '<div style="min-width: 250px;">';
            html += '<select name="zone_map[' + source.id_zone + ']" class="ps-input" style="height: 36px; padding: 4px 8px;">';
            html += '<option value="">-- skip --</option>';
            var found = false;
            $.each(localZones, function (j, local) {
                var selected = "";
                if (!found && local.name.toLowerCase().trim() == source.name.toLowerCase().trim()) {
                    selected = "selected"; found = true;
                }
                html += '<option value="' + local.id_zone + '" ' + selected + '>' + local.name + '</option>';
            });
            html += '</select></div></div>';
        });
        html += '</div>';
        $('#zone-mapping-table-container').html(html);
    },

    loadPreview: function () {
        var allData = [];
        if (PrestaShift.connectionData) allData = allData.concat(PrestaShift.connectionData);
        var formData = $.param(allData);

        $.ajax({
            url: controller_url,
            type: 'POST',
            data: formData + '&ajax=true&action=preview',
            dataType: 'json',
            success: function (response) {
                if (response.success && response.counts) {
                    var c = response.counts;
                    var labels = {
                        products: 'Products', categories: 'Categories', customers: 'Customers',
                        orders: 'Orders', manufacturers: 'Manufacturers', carriers: 'Carriers',
                        cms: 'CMS Pages', images: 'Images', cart_rules: 'Cart Rules'
                    };
                    // Map preview keys to scope keys
                    var scopeMap = {
                        products: 'catalog', categories: 'catalog', manufacturers: 'manufacturers',
                        customers: 'customers', orders: 'orders', carriers: 'carriers',
                        cms: 'cms', images: 'images', cart_rules: 'cart_rules'
                    };
                    // Check which scopes are enabled
                    var enabledScopes = {};
                    if (PrestaShift.scopeData) {
                        $.each(PrestaShift.scopeData, function (i, field) {
                            var m = field.name.match(/^scope\[(\w+)\]$/);
                            if (m && field.value == '1') enabledScopes[m[1]] = true;
                        });
                    }
                    var html = '<div style="display:grid; grid-template-columns:repeat(3,1fr); gap:8px;">';
                    var shown = 0;
                    $.each(c, function (key, count) {
                        var reqScope = scopeMap[key];
                        if (reqScope && !enabledScopes[reqScope]) return; // skip disabled scopes
                        var label = labels[key] || key;
                        var color = count > 0 ? '#1e40af' : '#94a3b8';
                        html += '<div style="text-align:center; padding:8px; background:white; border-radius:6px; border:1px solid #e2e8f0;">';
                        html += '<div style="font-size:1.25rem; font-weight:700; color:' + color + ';">' + new Intl.NumberFormat().format(count) + '</div>';
                        html += '<div style="font-size:0.75rem; color:#64748b;">' + label + '</div>';
                        html += '</div>';
                        shown++;
                    });
                    html += '</div>';
                    if (shown === 0) html = '<p style="color:#64748b; text-align:center;">No data scopes selected</p>';
                    $('#preview-data').html(html);
                } else {
                    $('#preview-data').html('<span style="color:#dc2626;">Could not load preview</span>');
                }
            },
            error: function () {
                $('#preview-data').html('<span style="color:#94a3b8;">Preview unavailable</span>');
            }
        });
    },

    runPostMigration: function () {
        $.ajax({
            url: controller_url,
            type: 'POST',
            data: 'ajax=true&action=post_migration',
            dataType: 'json',
            success: function (response) {
                if (response.success && response.tasks) {
                    $.each(response.tasks, function (i, t) {
                        var icon = t.ok ? '&#10003;' : '&#10007;';
                        var color = t.ok ? '#16a34a' : '#dc2626';
                        PrestaShift.log('<span style="color:' + color + ';">' + icon + '</span> ' + t.label);
                    });
                }
                PrestaShift.log('<strong>Migration Completed.</strong>');

                // Build final stats grid from report, filtered by selected scopes
                if (response.report) {
                    var r = response.report;
                    var reportLabels = {
                        products: 'Products', categories: 'Categories', customers: 'Customers',
                        orders: 'Orders', manufacturers: 'Manufacturers', cms_pages: 'CMS Pages',
                        images: 'Images', carriers: 'Carriers'
                    };
                    var reportScopeMap = {
                        products: 'catalog', categories: 'catalog', manufacturers: 'manufacturers',
                        customers: 'customers', orders: 'orders', carriers: 'carriers',
                        cms_pages: 'cms', images: 'images'
                    };
                    var enabledScopes = {};
                    if (PrestaShift.scopeData) {
                        $.each(PrestaShift.scopeData, function (i, field) {
                            var m = field.name.match(/^scope\[(\w+)\]$/);
                            if (m && field.value == '1') enabledScopes[m[1]] = true;
                        });
                    }
                    var statsHtml = '';
                    var colors = ['#2563eb', '#7c3aed', '#059669', '#d97706', '#dc2626', '#0891b2', '#4f46e5', '#16a34a'];
                    var ci = 0;
                    $.each(r, function (key, count) {
                        var reqScope = reportScopeMap[key];
                        if (reqScope && !enabledScopes[reqScope]) return;
                        var label = reportLabels[key] || key;
                        var color = colors[ci % colors.length]; ci++;
                        statsHtml += '<div style="padding: 2rem 1rem; border-right: 1px solid #e2e8f0;">';
                        statsHtml += '<p style="font-size:1.875rem; font-weight:700; color:' + color + '; margin-bottom:0.5rem;">' + new Intl.NumberFormat().format(count) + '</p>';
                        statsHtml += '<p class="ps-text-sm ps-text-slate-500 ps-font-medium">' + label + '</p>';
                        statsHtml += '</div>';
                    });
                    $('#final-stats-grid').html(statsHtml);
                }

                $('#main-progress-bar').css('width', '100%');
                $('#main-progress-text').text('100%');
                $('#migration-progress-container').hide();
                $('#migration-finished-container').show();
            },
            error: function () {
                PrestaShift.log('Post-migration tasks failed — please clear cache manually.');
                $('#migration-progress-container').hide();
                $('#migration-finished-container').show();
            }
        });
    },

    showVersionWarnings: function () {
        if (!PrestaShift.versionWarnings || PrestaShift.versionWarnings.length === 0) return;
        var html = '<div class="ps-card ps-p-4" style="border:1px solid #f59e0b; background:#fffbeb; margin-bottom:1.5rem;">';
        html += '<div class="ps-flex-start ps-gap-2" style="margin-bottom:8px;">';
        html += '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';
        html += '<span class="ps-font-semibold" style="color:#92400e;">' + PrestaShift.sourceVersion + ' → ' + PrestaShift.targetVersion + '</span>';
        html += '</div>';
        html += '<ul style="margin:0; padding-left:1.2rem; color:#78350f; font-size:0.875rem;">';
        $.each(PrestaShift.versionWarnings, function (i, w) {
            html += '<li style="margin-bottom:4px;">' + w + '</li>';
        });
        html += '</ul></div>';
        $('#scope-form').before(html);
    },

    enforceScopeDependencies: function () {
        var $form = $('#scope-form');
        if (!$form.length) return;

        // If customers is OFF, disable orders and messages
        var customersOn = $form.find('input[name="scope[customers]"]').is(':checked');
        var $orders = $form.find('input[name="scope[orders]"]');
        var $messages = $form.find('input[name="scope[messages]"]');

        if (!customersOn) {
            $orders.prop('checked', false).prop('disabled', true);
            $messages.prop('checked', false).prop('disabled', true);
            $orders.closest('.ps-checkbox-wrapper').css('opacity', '0.5');
            $messages.closest('.ps-checkbox-wrapper').css('opacity', '0.5');
        } else {
            $orders.prop('disabled', false);
            $messages.prop('disabled', false);
            $orders.closest('.ps-checkbox-wrapper').css('opacity', '1');
            $messages.closest('.ps-checkbox-wrapper').css('opacity', '1');
        }
    },

    loadStatusMapping: function () {
        var ordersEnabled = false;
        if (PrestaShift.scopeData) {
            $.each(PrestaShift.scopeData, function (i, field) {
                if (field.name === 'scope[orders]' && field.value == '1') ordersEnabled = true;
            });
        }
        if (!ordersEnabled) { $('#status-mapping-area').hide(); return; }

        $('#status-mapping-area').show();
        var allData = [];
        if (PrestaShift.connectionData) allData = allData.concat(PrestaShift.connectionData);
        var formData = $.param(allData);

        $.ajax({
            url: controller_url,
            type: 'POST',
            data: formData + '&ajax=true&action=get_source_statuses',
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    PrestaShift.renderStatusTable(response.source_statuses, response.local_statuses);
                } else {
                    $('#status-mapping-table-container').html('<div class="alert alert-danger">' + response.message + '</div>');
                }
            },
            error: function () {
                $('#status-mapping-table-container').html('<div class="alert alert-danger">Error fetching statuses</div>');
            }
        });
    },

    renderStatusTable: function (sourceConfigs, localStatuses) {
        var html = '<div class="ps-space-y-2">';
        $.each(sourceConfigs, function (i, source) {
            html += '<div class="ps-flex-between ps-p-3 ps-border ps-border-slate-200 ps-rounded-md ps-bg-slate-50">';

            // Name Column: flex: 1 to push arrow/select to the right
            html += '<div class="ps-flex-start ps-gap-2" style="flex: 1;">';
            html += '<span class="ps-font-medium ps-text-slate-700">' + source.name + '</span>';
            html += '<span class="ps-text-xs ps-text-slate-400">(' + source.id_order_state + ')</span>';
            html += '</div>';

            // Arrow: Padding to avoid sticking
            html += '<div class="ps-text-slate-400" style="padding: 0 24px;"><i class="icon-long-arrow-right"></i></div>';

            // Select: Fixed min-width
            html += '<div style="min-width: 250px;">';
            html += '<select name="status_map[' + source.id_order_state + ']" class="ps-input" style="height: 36px; padding: 4px 8px;">';
            var found = false;
            $.each(localStatuses, function (j, local) {
                var selected = "";
                if (!found && local.name.toLowerCase().trim() == source.name.toLowerCase().trim()) {
                    selected = "selected"; found = true;
                }
                html += '<option value="' + local.id_order_state + '" ' + selected + '>' + local.name + '</option>';
            });
            html += '</select></div></div>';
        });
        html += '</div>';
        $('#status-mapping-table-container').html(html);
    },

    startMigration: function () {
        var allData = [];
        if (PrestaShift.connectionData) allData = allData.concat(PrestaShift.connectionData);
        if (PrestaShift.scopeData) allData = allData.concat(PrestaShift.scopeData);
        if (PrestaShift.optionsData) allData = allData.concat(PrestaShift.optionsData);
        var formData = $.param(allData);

        // Run preflight checks first
        PrestaShift.log('Running pre-flight checks...');
        $.ajax({
            url: controller_url,
            type: 'POST',
            data: 'ajax=true&action=pre_flight',
            dataType: 'json',
            success: function (response) {
                if (response.success && response.checks) {
                    var html = '';
                    var hasWarnings = false;
                    $.each(response.checks, function (i, c) {
                        var icon = c.ok ? '<span style="color:#16a34a;">&#10003;</span>' : '<span style="color:#dc2626;">&#10007;</span>';
                        html += '<div style="display:flex;gap:8px;align-items:center;padding:4px 0;">';
                        html += icon + ' <strong>' + c.label + '</strong>: ' + c.value;
                        if (c.hint) { html += ' <em style="color:#92400e;">— ' + c.hint + '</em>'; hasWarnings = true; }
                        html += '</div>';
                    });
                    PrestaShift.log(html);

                    if (!response.all_ok) {
                        PrestaShift.log('<strong style="color:#dc2626;">Pre-flight warnings detected. Migration will proceed but may encounter issues.</strong>');
                    }
                }

                // Proceed with migration
                $('#migration-start-container').hide();
                $('#migration-progress-container').show();
                PrestaShift.log('Starting migration...');
                PrestaShift.runBatch(formData);
            },
            error: function () {
                // Preflight failed, start anyway
                $('#migration-start-container').hide();
                $('#migration-progress-container').show();
                PrestaShift.log('Starting migration...');
                PrestaShift.runBatch(formData);
            }
        });
    },

    runBatch: function (data) {
        var ajaxData = (typeof data === 'string') ? data + '&ajax=true&action=start_migration' : data;

        $.ajax({
            url: controller_url,
            type: 'POST',
            data: ajaxData,
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    PrestaShift.log(response.message);
                    $('#current-action').text(response.message);

                    // Track stats
                    if (response.state && response.state.current_task) {
                        var task = response.state.current_task;
                        var count = response.batch_count || 0; // Backend might need to provide this or we parse it

                        if (task === 'products') PrestaShift.stats.products += count;
                        if (task === 'orders') PrestaShift.stats.orders += count;
                        if (task === 'customers') PrestaShift.stats.customers += count;
                    }

                    if (response.progress) {
                        $('#main-progress-bar').css('width', response.progress + '%');
                        $('#main-progress-text').text(response.progress + '%');
                    }
                    if (response.next_batch) {
                        var nextPayload = { ajax: true, action: 'run_batch', state: JSON.stringify(response.state) };

                        if (PrestaShift.isPaused) {
                            PrestaShift.pendingNextBatch = nextPayload;
                            PrestaShift.log('Migration paused by user.');
                            $('#btn-pause-migration')
                                .html('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-play" style="margin-right: 8px;"><polygon points="5 3 19 12 5 21 5 3"/></svg> Resume Migration')
                                .removeClass('ps-opacity-50')
                                .prop('disabled', false);
                            $('#finish-later-area').css('visibility', 'visible');
                        } else {
                            PrestaShift.runBatch(nextPayload);
                        }
                    } else {
                        // Run post-migration tasks before showing finished screen
                        PrestaShift.log('Data transfer complete. Running post-migration tasks...');
                        $('#current-action').text('Running post-migration tasks...');
                        PrestaShift.runPostMigration();
                    }
                } else {
                    PrestaShift.log('Error: ' + response.message);
                }
            },
            error: function (jqXHR) {
                PrestaShift.log('Communication Error (Network/Timeout). Click Resume to retry this batch.');
                PrestaShift.isPaused = true;
                PrestaShift.pendingNextBatch = ajaxData;

                $('#btn-pause-migration')
                    .html('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-play" style="margin-right: 8px;"><polygon points="5 3 19 12 5 21 5 3"/></svg> Resume Migration')
                    .removeClass('ps-opacity-50')
                    .prop('disabled', false)
                    .show();
                $('#finish-later-area').css('visibility', 'visible');
            }
        });
    },

    log: function (msg) {
        var $log = $('#migration-log');
        if (!$log.length) return;

        var time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
        var html = '<div class="ps-terminal-line">' +
            '<span class="ps-terminal-time">[' + time + ']</span>' +
            '<span class="ps-terminal-msg">' + msg + '</span>' +
            '</div>';

        $log.append(html);

        var $container = $('#migration-log-container');
        if ($container.length) {
            $container.scrollTop($container[0].scrollHeight);
        }
    },

    checkSavedState: function () {
        $.ajax({
            url: controller_url,
            type: 'POST',
            data: { ajax: true, action: 'get_saved_state' },
            dataType: 'json',
            success: function (response) {
                if (response.success && response.has_state) {
                    var s = response.state;
                    var taskName = s.current_task || 'Unknown';
                    var offset = s.offset || 0;

                    var html = '<div class="ps-card ps-p-6 ps-bg-amber-50 ps-border-amber-400" style="display: flex; justify-content: space-between; align-items: center; gap: 1.5rem;">' +
                        '  <div class="ps-flex-start ps-gap-3">' +
                        '    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-alert-triangle"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12" y1="17" y2="17.01"/></svg>' +
                        '    <div class="ps-space-y-1">' +
                        '      <p class="ps-font-bold ps-text-slate-900">' + PrestaShift.t('detected_interrupted') + '</p>' +
                        '      <p class="ps-text-sm ps-text-slate-600">' + PrestaShift.t('resuming') + ' <strong>' + PrestaShift.t(taskName) + '</strong> ' + PrestaShift.t('offset') + offset + '</p>' +
                        '    </div>' +
                        '  </div>' +
                        '  <div style="display: flex; gap: 1rem; align-items: center;">' +
                        '    <button type="button" class="ps-btn ps-btn-primary" id="btn-resume-session">' + PrestaShift.t('resume_button') + '</button>' +
                        '    <a href="#" class="ps-text-xs ps-text-slate-500 ps-font-medium" id="btn-clear-session" style="text-decoration: underline;">' + PrestaShift.t('reset_session') + '</a>' +
                        '  </div>' +
                        '</div>';

                    $('#resume-session-area').html(html).fadeIn();

                    $('#btn-resume-session').on('click', function () {
                        PrestaShift.resumeMigration(s);
                    });

                    $('#btn-clear-session').on('click', function (e) {
                        e.preventDefault();
                        if (confirm('Are you sure you want to clear the saved session?')) {
                            PrestaShift.clearSavedState();
                        }
                    });
                }
            }
        });
    },

    resumeMigration: function (state) {
        // 1. Mark Steps 1-3 as completed in UI
        $('#migration-stepper .ps-step-item').each(function () {
            var s = parseInt($(this).data('step'));
            if (s < 4) {
                $(this).find('.ps-step-content').removeClass('ps-step-active').addClass('ps-step-completed');
                $(this).find('.ps-step-line').addClass('ps-step-line-active');
            } else if (s === 4) {
                $(this).find('.ps-step-content').addClass('ps-step-active');
            }
        });

        // 2. Load Step 4 template and start batching
        $.ajax({
            url: controller_url,
            type: 'POST',
            data: { ajax: true, action: 'render_step', step: 4 },
            dataType: 'json',
            success: function (response) {
                $('#step-content').html(response.content);
                $('#migration-start-container').hide();
                $('#migration-progress-container').show();

                PrestaShift.log(PrestaShift.t('resuming') + ' ' + PrestaShift.t(state.current_task));

                var firstPayload = {
                    ajax: true,
                    action: 'run_batch',
                    state: JSON.stringify(state)
                };
                PrestaShift.runBatch(firstPayload);
            }
        });
    },

    clearSavedState: function () {
        $.ajax({
            url: controller_url,
            type: 'POST',
            data: { ajax: true, action: 'clear_saved_state' },
            dataType: 'json',
            success: function () {
                $('#resume-session-area').fadeOut();
            }
        });
    },

    pauseMigration: function () {
        if (this.isPaused) {
            this.resumeMigrationInPlace();
            return;
        }

        this.isPaused = true;
        $('#btn-pause-migration').html('<i class="icon-spinner icon-spin"></i> Pausing...');
        $('#btn-pause-migration').addClass('ps-opacity-50').prop('disabled', true);
    },

    resumeMigrationInPlace: function () {
        this.isPaused = false;
        $('#btn-pause-migration').html('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pause" style="margin-right: 8px;"><rect width="4" height="16" x="6" y="4" rx="1"/><rect width="4" height="16" x="14" y="4" rx="1"/></svg> Pause Migration');
        $('#btn-pause-migration').removeClass('ps-opacity-50').prop('disabled', false);
        $('#finish-later-area').css('visibility', 'hidden');

        if (this.pendingNextBatch) {
            var payload = this.pendingNextBatch;
            this.pendingNextBatch = null;
            this.runBatch(payload);
        }
    },

    finishLater: function () {
        window.location.href = controller_url;
    },
};

$(document).ready(function () {
    if (typeof controller_url === 'undefined') return;
    PrestaShift.init();
});
