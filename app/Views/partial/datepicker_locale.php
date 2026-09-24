<?php
use Config\OSPOS;

$config = config(OSPOS::class)->settings; ?>

var pickerconfig = function(config) {
    return $.extend({
        format: "<?= $this->data["format"] ?? dateformat_bootstrap($config['dateformat']) . ' ' . dateformat_bootstrap($config['timeformat'])?>",
        <?php
        $t = $config['timeformat'];
        $m = $t[strlen($t) - 1];
        if (str_contains($config['timeformat'], 'a') || str_contains($config['timeformat'], 'A')) {
        ?>
            showMeridian: true,
        <?php } else {  ?>
            showMeridian: false,
        <?php } ?>
        minView: 2,
        minuteStep: 1,
        autoclose: true,
        todayBtn: true,
        todayHighlight: true,
        bootcssVer: 3,
        language: "<?= current_language_code() ?>"
    }, <?php echo isset($config) ?>);
};

$.fn.datetimepicker.dates['<?= $config['language'] ?>'] = {
    days: [
        "<?= lang('Calendar.sunday') ?>",
        "<?= lang('Calendar.monday') ?>",
        "<?= lang('Calendar.tuesday') ?>",
        "<?= lang('Calendar.wednesday') ?>",
        "<?= lang('Calendar.thursday') ?>",
        "<?= lang('Calendar.friday') ?>",
        "<?= lang('Calendar.saturday') ?>"
    ],
    daysShort: [
        "<?= lang('Calendar.sun') ?>",
        "<?= lang('Calendar.mon') ?>",
        "<?= lang('Calendar.tue') ?>",
        "<?= lang('Calendar.wed') ?>",
        "<?= lang('Calendar.thu') ?>",
        "<?= lang('Calendar.fri') ?>",
        "<?= lang('Calendar.sat') ?>"
    ],
    daysMin: [
        "<?= lang('Calendar.su') ?>",
        "<?= lang('Calendar.mo') ?>",
        "<?= lang('Calendar.tu') ?>",
        "<?= lang('Calendar.we') ?>",
        "<?= lang('Calendar.th') ?>",
        "<?= lang('Calendar.fr') ?>",
        "<?= lang('Calendar.sa') ?>"
    ],
    months: [
        "<?= lang('Calendar.january') ?>",
        "<?= lang('Calendar.february') ?>",
        "<?= lang('Calendar.march') ?>",
        "<?= lang('Calendar.april') ?>",
        "<?= lang('Calendar.may') ?>",
        "<?= lang('Calendar.june') ?>",
        "<?= lang('Calendar.july') ?>",
        "<?= lang('Calendar.august') ?>",
        "<?= lang('Calendar.september') ?>",
        "<?= lang('Calendar.october') ?>",
        "<?= lang('Calendar.november') ?>",
        "<?= lang('Calendar.december') ?>"
    ],
    monthsShort: [
        "<?= lang('Calendar.jan') ?>",
        "<?= lang('Calendar.feb') ?>",
        "<?= lang('Calendar.mar') ?>",
        "<?= lang('Calendar.apr') ?>",
        "<?= lang('Calendar.may') ?>",
        "<?= lang('Calendar.jun') ?>",
        "<?= lang('Calendar.jul') ?>",
        "<?= lang('Calendar.aug') ?>",
        "<?= lang('Calendar.sep') ?>",
        "<?= lang('Calendar.oct') ?>",
        "<?= lang('Calendar.nov') ?>",
        "<?= lang('Calendar.dec') ?>"
    ],
    today: "<?= lang('Datepicker.today') ?>",
    <?php if (str_contains($config['timeformat'], 'a')) { ?>
        meridiem: ["am", "pm"],
    <?php } elseif (str_contains($config['timeformat'], 'A')) { ?>
        meridiem: ["AM", "PM"],
    <?php } else { ?>
        meridiem: [],
    <?php } ?>
    weekStart: <?= lang('Datepicker.weekstart') ?>
};

$(".datetime").datetimepicker(pickerconfig());

/*
 * The date in words under every typed date field, so the person confirms it before saving (D27).
 *
 * "05/09/2026" is a real date in both orders; only the person knows which one they meant, so the
 * screen says "= sábado, 5 de septiembre de 2026" and they can see it. A date that is only real the
 * other way round ("09/30/2026", the old month-first habit) is rearranged in the field and the hint
 * says so -- the same rule the server applies (parse_typed_datetime()). A date that is not real
 * either way turns the field red; the server refuses it too.
 *
 * Words come from the browser (Intl), not from a moment.js locale file this bundle does not ship.
 * One namespaced delegate: forms in modals load this partial every time they open.
 */
(function() {
    var FORMAT = "<?= dateformat_momentjs($config['dateformat']) ?>";
    var TIME = "<?= dateformat_momentjs($config['timeformat']) ?>";
    var REORDERED = <?= json_encode(lang('Common.date_reordered')) ?>;
    var NOT_REAL = <?= json_encode(lang('Common.date_not_real')) ?>;
    var SELECTOR = '.datetime, #datetime, #open_date, #close_date, #collection_collected_at';
    var words = new Intl.DateTimeFormat(<?= json_encode(current_language_code()) ?>, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

    var swap = function(format) {
        return format.replace(/DD|MM/g, function(token) { return token === 'DD' ? 'MM' : 'DD'; });
    };

    var readback = function($input) {
        var $hint = $input.data('date-readback');

        if (!$hint) {
            $hint = $('<span class="help-block date-readback" aria-live="polite"></span>');
            ($input.closest('.input-group').length ? $input.closest('.input-group') : $input).after($hint);
            $input.data('date-readback', $hint);
        }

        var value = $.trim($input.val());
        var $group = $input.closest('.form-group');

        if (value === '') {
            $hint.text('');
            $group.removeClass('has-error');
            return;
        }

        var withTime = FORMAT + ' ' + TIME;
        var date = moment(value, [withTime, FORMAT], true);
        var note = '';

        if (!date.isValid()) {
            var swapped = moment(value, [swap(withTime), swap(FORMAT)], true);

            if (swapped.isValid()) {
                var hadTime = moment(value, swap(withTime), true).isValid();
                $input.val(swapped.format(hadTime ? withTime : FORMAT));
                date = swapped;
                note = ' (' + REORDERED + ')';
            }
        }

        if (!date.isValid()) {
            $hint.text(NOT_REAL);
            $group.addClass('has-error');
            return;
        }

        $hint.text('= ' + words.format(date.toDate()) + note);
        $group.removeClass('has-error');
    };

    $(document)
        .off('.datereadback')
        .on('change.datereadback blur.datereadback changeDate.datereadback', SELECTOR, function() {
            readback($(this));
        });

    $(SELECTOR).each(function() {
        readback($(this));
    });
})();
