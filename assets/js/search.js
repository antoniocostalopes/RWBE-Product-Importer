/* global rwbeYmm, jQuery */
(function ($) {
    'use strict';

    // Cached promises for the two static map files. Each file is fetched at most
    // once per page view; every dropdown change after that is pure JavaScript.
    var maps = {};

    function single($select, text, disabled) {
        if (!$select.length) return;
        $select.empty().append($('<option>').val('').text(text)).prop('disabled', !!disabled);
    }

    // The static map stores an entry as a bare string when its slug and label are
    // identical (years), and as {slug, name} otherwise (models, and the AJAX
    // fallback). Normalise both to {slug, name}.
    function normalize(items) {
        return (items || []).map(function (it) {
            return (typeof it === 'string') ? { slug: it, name: it } : it;
        });
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

    /**
     * Fetch one of the static map files, reusing the in-page cache.
     * Rejects when the URL is not configured or the request fails, which is the
     * signal to fall back to the admin-ajax endpoints.
     */
    function loadMap(key, url) {
        if (!url) {
            return $.Deferred().reject().promise();
        }
        if (!maps[key]) {
            maps[key] = $.ajax({ url: url, dataType: 'json', cache: true });
        }
        return maps[key];
    }

    function loadModels($make, $model, $year, preModel, preYear) {
        var make = $make.val();

        // Reset downstream
        single($year, rwbeYmm.i18n.selectYear, true);

        if (!make) {
            single($model, rwbeYmm.i18n.selectModel, true);
            return;
        }

        single($model, rwbeYmm.i18n.loading, true);

        function apply(list) {
            var models = normalize(list);
            if (models.length) {
                fill($model, models, rwbeYmm.i18n.selectModel, preModel);
                // Chain to years if a model is pre-selected (results page)
                if (preModel && $model.val() === preModel) {
                    loadYears($make, $model, $year, preYear);
                }
            } else {
                single($model, rwbeYmm.i18n.noModels, true);
            }
        }

        loadMap('models', rwbeYmm.modelsUrl).done(function (data) {
            apply((data && data.models && data.models[make]) ? data.models[make] : []);
        }).fail(function () {
            // Fallback: the static map is missing or unreadable.
            $.post(rwbeYmm.ajaxUrl, {
                action: 'rwbe_ymm_get_models',
                nonce: rwbeYmm.nonce,
                make: make
            }).done(function (res) {
                apply((res && res.success && res.data && res.data.models) ? res.data.models : []);
            }).fail(function () {
                single($model, rwbeYmm.i18n.selectModel, false);
            });
        });
    }

    function loadYears($make, $model, $year, preYear) {
        if (!$year.length) return;

        var make = $make.val();
        var model = $model.val();

        if (!make || !model) {
            single($year, rwbeYmm.i18n.selectYear, true);
            return;
        }

        single($year, rwbeYmm.i18n.loading, true);

        function apply(list) {
            var years = normalize(list);
            if (years.length) {
                fill($year, years, rwbeYmm.i18n.selectYear, preYear);
            } else {
                single($year, rwbeYmm.i18n.noYears, true);
            }
        }

        loadMap('years', rwbeYmm.yearsUrl).done(function (data) {
            var key = make + '|' + model;
            apply((data && data.years && data.years[key]) ? data.years[key] : []);
        }).fail(function () {
            $.post(rwbeYmm.ajaxUrl, {
                action: 'rwbe_ymm_get_years',
                nonce: rwbeYmm.nonce,
                make: make,
                model: model
            }).done(function (res) {
                apply((res && res.success && res.data && res.data.years) ? res.data.years : []);
            }).fail(function () {
                single($year, rwbeYmm.i18n.selectYear, false);
            });
        });
    }

    $(function () {
        $('.rwbe-ymm-search').each(function () {
            var $form = $(this);
            var $make = $form.find('.rwbe-ymm-make');
            var $model = $form.find('.rwbe-ymm-model');
            var $year = $form.find('.rwbe-ymm-year');
            var preModel = $model.data('selected') ? String($model.data('selected')) : '';
            var preYear = ($year.length && $year.data('selected')) ? String($year.data('selected')) : '';

            // Warm the model map as soon as the form is on screen, so the first
            // "change" already has the data in memory.
            loadMap('models', rwbeYmm.modelsUrl);

            // Restore chained state on the results page
            if ($make.val()) {
                loadModels($make, $model, $year, preModel, preYear);
            }

            $make.on('change', function () { loadModels($make, $model, $year, '', ''); });
            $model.on('change', function () { loadYears($make, $model, $year, ''); });

            // Require at least a make before submitting
            $form.on('submit', function (e) {
                if (!$make.val()) {
                    e.preventDefault();
                    $make.focus();
                }
            });
        });
    });
})(jQuery);
