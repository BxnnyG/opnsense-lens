{#
 # Copyright (C) 2026 Benny <claude@bxnny.de>
 # All rights reserved.
 #
 # Redistribution and use in source and binary forms, with or without modification,
 # are permitted provided that the following conditions are met:
 #
 # 1.  Redistributions of source code must retain the above copyright notice,
 #     this list of conditions and the following disclaimer.
 #
 # 2.  Redistributions in binary form must reproduce the above copyright notice,
 #     this list of conditions and the following disclaimer in the documentation
 #     and/or other materials provided with the distribution.
 #
 # THIS SOFTWARE IS PROVIDED "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
 # INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 # AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 # AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 # OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 # SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 # INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 # CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 # ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 # POSSIBILITY OF SUCH DAMAGE.
 #}

<link rel="stylesheet" type="text/css" href="{{ cache_safe('/ui/css/lens.css') }}">
<script src="{{ cache_safe('/ui/js/lens.js') }}"></script>

<script>
    $(document).ready(() => {
        /*
         * Layout only. Which settings exist, their bounds and every sentence on
         * this page come from Settings (PHP) and lenslib/settings.py (the
         * collector), which refuses anything outside the bounds. Nothing typed
         * here is trusted because of what this page checked (§4.58).
         */
        let form = null;

        const say = (text) => $('<span/>').text(text === undefined || text === null ? '' : String(text));

        const number = (field) => {
            const $input = $('<input type="number" class="form-control input-sm"/>')
                .attr({ id: 'lensSet_' + field.key, min: field.min, max: field.max, step: field.step })
                .val(field.value);
            return $('<div/>').addClass('lens-field-input')
                .append($input)
                .append(say(field.unit));
        };

        const flag = (field) => $('<div/>').addClass('lens-field-input').append(
            $('<input type="checkbox"/>').attr('id', 'lensSet_' + field.key).prop('checked', !!field.value)
        );

        const targets = (field) => {
            const $box = $('<div/>').attr('id', 'lensSet_' + field.key);
            const rows = field.value || [];
            for (let n = 0; n < field.rows; n++) {
                const row = rows[n] || { name: '', address: '' };
                $box.append($('<div/>').addClass('lens-target')
                    .append($('<input type="text" class="form-control input-sm lens-target-name"/>')
                        .attr({ placeholder: '{{ lang._("name") }}', maxlength: 24 }).val(row.name))
                    .append($('<input type="text" class="form-control input-sm lens-target-address"/>')
                        .attr({ placeholder: '{{ lang._("IPv4 address") }}', maxlength: 15 }).val(row.address)));
            }
            return $('<div/>').addClass('lens-field-input').append($box);
        };

        /* one tick per network the box has; the value is sent as one string so
           that ticking none still says so (an empty list is never posted) */
        const networks = (field) => {
            const $box = $('<div/>').attr('id', 'lensSet_' + field.key).addClass('lens-networks');
            const chosen = field.value || [];
            for (const [device, name] of Object.entries(field.choices || {})) {
                $box.append($('<label/>').addClass('lens-network')
                    .append($('<input type="checkbox"/>').val(device).prop('checked', chosen.includes(device)))
                    .append(document.createTextNode(' ' + name)));
            }
            return $('<div/>').addClass('lens-field-input').append($box);
        };

        const shown = (field, value) => {
            if (field.kind === 'interfaces') {
                return (value || []).length
                    ? value.map(d => (field.choices || {})[d] || d).join(', ')
                    : '{{ lang._("none: the network you click from") }}';
            }
            if (field.kind === 'flag') {
                return value ? '{{ lang._("on") }}' : '{{ lang._("off") }}';
            }
            if (field.kind === 'targets') {
                return (value || []).map(t => t.name + ' ' + t.address).join(', ');
            }
            return value + (field.unit ? ' ' + field.unit : '');
        };

        const render = () => {
            const $blocks = $('#lensSettingsBlocks').empty();

            for (const block of form.blocks) {
                const $box = $('<div/>').addClass('content-box lens-box')
                    .append($('<div/>').addClass('lens-box-head').text(block.title));
                if (block.intro) {
                    $box.append($('<div/>').addClass('lens-box-intro').text(block.intro));
                }

                for (const field of block.fields) {
                    const input = field.kind === 'flag' ? flag(field)
                        : field.kind === 'targets' ? targets(field)
                        : field.kind === 'interfaces' ? networks(field) : number(field);

                    const $help = $('<div/>').addClass('lens-field-help').text(field.help)
                        .append($('<span/>').addClass('lens-field-default text-muted')
                            .text('{{ lang._("Default:") }} ' + shown(field, field.default)));

                    $box.append($('<div/>').addClass('lens-field')
                        .append($('<div/>').addClass('lens-field-label').text(field.label))
                        .append(input)
                        .append($help)
                        .append($('<div/>').addClass('lens-field-error').attr('id', 'lensErr_' + field.key)));
                }

                $blocks.append($box);
            }

            $('#lensBackup').text(form.backup || '');
        };

        const collect = () => {
            const fields = {};
            for (const block of form.blocks) {
                for (const field of block.fields) {
                    const $el = $('#lensSet_' + field.key);
                    if (field.kind === 'interfaces') {
                        fields[field.key] = $el.find('input:checked').map(function () {
                            return $(this).val();
                        }).get().join(',');
                    } else if (field.kind === 'flag') {
                        fields[field.key] = $el.prop('checked') ? '1' : '0';
                    } else if (field.kind === 'targets') {
                        fields[field.key] = $el.find('.lens-target').map(function () {
                            return {
                                name: $(this).find('.lens-target-name').val(),
                                address: $(this).find('.lens-target-address').val()
                            };
                        }).get();
                    } else {
                        fields[field.key] = $el.val();
                    }
                }
            }
            return fields;
        };

        const list = ($ul, lines) => {
            $ul.empty();
            for (const line of (lines || [])) {
                $ul.append($('<li/>').text(line));
            }
        };

        const load = () => ajaxGet('/api/lens/settings/get', {}, (reply, status) => {
            $('#lensLoading').hide();

            if (status !== 'success' || !reply || !reply.available) {
                $('#lensError').show();
                $('#lensSettings').hide();
                return;
            }

            form = reply;
            render();
            list($('#lensPurgeRemoves'), form.purge.removes);
            list($('#lensPurgeKeeps'), form.purge.keeps);
            list($('#lensPurgeAfter'), form.purge.after);
            $('#lensSettings').show();
        });

        $('#lensSave').on('click', function () {
            const $button = $(this).prop('disabled', true);
            $('.lens-field-error').hide().text('');
            $('#lensSaved').hide();
            $('#lensSaveError').hide();

            ajaxCall('/api/lens/settings/set', collect(), (reply, status) => {
                $button.prop('disabled', false);

                if (status === 'success' && reply && reply.status === 'ok') {
                    $('#lensSaved').show();
                    load();
                    return;
                }

                if (status === 'success' && reply && reply.status === 'invalid') {
                    /* a sentence with no field of its own still reaches the page */
                    const loose = [];
                    for (const [key, sentence] of Object.entries(reply.errors || {})) {
                        const $err = $('#lensErr_' + key);
                        if ($err.length) {
                            $err.text(sentence).show();
                        } else {
                            loose.push(sentence);
                        }
                    }
                    $('#lensSaveError').text(
                        ['{{ lang._("Nothing was saved: a value is outside what Lens accepts.") }}'].concat(loose).join(' ')
                    ).show();
                    return;
                }

                $('#lensSaveError')
                    .text((reply && reply.message) || '{{ lang._("Lens did not answer. Nothing was changed.") }}')
                    .show();
            });
        });

        $('#lensPurgeOpen').on('click', () => {
            $('#lensPurgeError').hide();
            $('#lensPurgeDone').hide();
            $('#lensPurgeApply').show().prop('disabled', false);
            $('#lensPurgeDialog').modal('show');
        });

        $('#lensPurgeApply').on('click', function () {
            $(this).prop('disabled', true);

            ajaxCall('/api/lens/settings/purge', {}, (reply, status) => {
                if (status !== 'success' || !reply || reply.status !== 'ok') {
                    $('#lensPurgeError')
                        .text((reply && reply.message) || '{{ lang._("Nothing was deleted.") }}')
                        .show();
                    $('#lensPurgeApply').prop('disabled', false);
                    return;
                }

                $('#lensPurgeApply').hide();
                $('#lensPurgeDone').text(reply.result).show();
                load();
            });
        });

        load();
    });
</script>

<div id="lensLoading">
    <i class="fa fa-spinner fa-spin"></i>
    {{ lang._('Reading the settings...') }}
</div>

<div id="lensError" class="alert alert-danger" style="display: none;">
    {{ lang._('The settings did not come back. The store may not exist yet: the collector creates it on its first run, within five minutes of installing.') }}
</div>

<div id="lensSettings" style="display: none;">
    <div id="lensSettingsBlocks"></div>

    <div class="lens-actions">
        <button type="button" class="btn btn-primary" id="lensSave">{{ lang._('Save') }}</button>
        <span id="lensSaved" class="text-success" style="display: none;">
            <i class="fa fa-check"></i> {{ lang._('Saved. The collector reads these at its next run.') }}
        </span>
        <span id="lensSaveError" class="text-danger" style="display: none;"></span>
    </div>

    <p id="lensBackup" class="text-muted"></p>

    <div id="lensPurgeBox" class="content-box lens-box">
        <div class="lens-box-head">{{ lang._('Delete everything') }}</div>
        <div class="lens-box-intro">
            {{ lang._('Everything Lens has collected about the devices on this network, at once. OPNsense itself only kept the last 24 hours of it, so this cannot be undone.') }}
        </div>
        <button type="button" class="btn btn-danger" id="lensPurgeOpen">{{ lang._('Delete everything Lens has collected') }}</button>
    </div>

    <div class="modal" id="lensPurgeDialog" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">{{ lang._('Delete everything Lens has collected') }}</h4>
                </div>
                <div class="modal-body">
                    <div id="lensPurgeError" class="alert alert-danger" style="display: none;"></div>
                    <div id="lensPurgeDone" class="alert alert-success" style="display: none;"></div>

                    <p>{{ lang._('This deletes:') }}</p>
                    <ul id="lensPurgeRemoves"></ul>
                    <p>{{ lang._('It keeps:') }}</p>
                    <ul id="lensPurgeKeeps"></ul>
                    <p>{{ lang._('Afterwards:') }}</p>
                    <ul id="lensPurgeAfter"></ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" data-dismiss="modal">{{ lang._('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" id="lensPurgeApply">{{ lang._('Delete it') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
