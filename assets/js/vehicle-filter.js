/* global rwbeVF, jQuery */
(function ($) {
    'use strict';

    // Cached promise for the static make→model map, shared with the shortcode.
    var modelMap = null;

    /**
     * Fetch the static map file once per page view. Rejects when it is not
     * configured or unreachable, which falls back to the admin-ajax endpoint.
     */
    function loadMap(url) {
        if (!url) {
            return $.Deferred().reject().promise();
        }
        if (!modelMap) {
            modelMap = $.ajax({ url: url, dataType: 'json', cache: true });
        }
        return modelMap;
    }

    function single($select, text, disabled) {
        if (!$select.length) return;
        $select.empty().append($('<option>').val('').text(text)).prop('disabled', !!disabled);
    }

    function fill($select, items, placeholder, preselect) {
        $select.empty().append($('<option>').val('').text(placeholder));
        items.forEach(function (it) {
            var $o = $('<option>').val(it.slug).text(it.name);
            if (it.slug && it.slug === preselect) {
                $o.prop('selected', true);
            }
            $select.append($o);
        });
        $select.prop('disabled', false);
    }

    // Navigate to the shop already filtered by the current selection.
    function go($form, make, model) {
        if (!make) return;
        var action = $form.prop('action'); // absolute URL resolved by the browser
        var url;
        try {
            url = new URL(action);
        } catch (e) {
            url = new URL(action, window.location.origin);
        }
        url.searchParams.set('rwbe_make', make);
        if (model) {
            url.searchParams.set('rwbe_model', model);
        } else {
            url.searchParams.delete('rwbe_model');
        }
        window.location.href = url.toString();
    }

    // Load the models for the selected make. On a real user change (userAction),
    // navigate straight to the make results if that make has no distinct models.
    function loadModels($form, $make, $model, preModel, userAction) {
        if (!$model.length) return;

        var make = $make.val();

        if (!make) {
            single($model, rwbeVF.i18n.selectModel, true);
            return;
        }

        single($model, rwbeVF.i18n.loading, true);

        function apply(models) {
            if (models.length) {
                fill($model, models, rwbeVF.i18n.selectModel, preModel);
            } else {
                single($model, rwbeVF.i18n.noModels, true);
                if (userAction) {
                    go($form, make, ''); // no models for this make → show make results
                }
            }
        }

        loadMap(rwbeVF.modelsUrl).done(function (data) {
            apply((data && data.models && data.models[make]) ? data.models[make] : []);
        }).fail(function () {
            // Fallback: the static map is missing or unreadable.
            $.post(rwbeVF.ajaxUrl, {
                action: 'rwbe_vf_get_models',
                nonce: rwbeVF.nonce,
                make: make
            }).done(function (res) {
                apply((res && res.success && res.data && res.data.models) ? res.data.models : []);
            }).fail(function () {
                single($model, rwbeVF.i18n.selectModel, false);
            });
        });
    }

    $(function () {
        $('.rwbe-vf-form').each(function () {
            var $form = $(this);
            var $make = $form.find('.rwbe-vf-make');
            var $model = $form.find('.rwbe-vf-model');
            var preModel = ($model.length && $model.data('selected')) ? String($model.data('selected')) : '';

            // Warm the map so the first make change resolves without a round trip.
            loadMap(rwbeVF.modelsUrl);

            // On the results page (make pre-selected) restore the model list without navigating.
            if ($make.val()) {
                loadModels($form, $make, $model, preModel, false);
            }

            // Selecting a make loads its models (AJAX). No navigation yet.
            $make.on('change', function () {
                loadModels($form, $make, $model, '', true);
            });

            // Selecting a model navigates to the filtered shop. No search button.
            $model.on('change', function () {
                var model = $model.val();
                if (model) {
                    go($form, $make.val(), model);
                }
            });

            // No-JS / accidental submit fallback: still go to the filtered shop.
            $form.on('submit', function (e) {
                e.preventDefault();
                go($form, $make.val(), $model.val());
            });
        });
    });
})(jQuery);
