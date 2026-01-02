/* global tinymce, KCC, jQuery */
(function($){
    'use strict';

    // AJAX helper
    function kccAjaxClean(html) {
        return $.post(KCC.ajaxUrl, {
            action: 'kcc_clean',
            nonce: KCC.nonce,
            content: html
        });
    }

    // --------------------------------------------------------
    //  TINY MCE (WordPress – API "ancienne", mais officielle)
    // --------------------------------------------------------
    if (typeof tinymce !== 'undefined') {

        tinymce.create('tinymce.plugins.kcc_clean_button', {

            init : function(editor){

                editor.addButton('kcc_clean_code', {
                    title : 'Nettoyer le code',
                    image : KCC.iconUrl,     // ← WP accepte image = URL
                    onclick : function() {

                        const original = editor.getContent({format:'raw'});
                        editor.setProgressState(true);

                        kccAjaxClean(original).done(function(res){
                            if (res && res.success && res.data && typeof res.data.cleaned === 'string') {
                                editor.setContent(res.data.cleaned, {format:'raw'});
                            } else {
                                alert('Réponse invalide du serveur.');
                            }
                        }).fail(function(){
                            alert('Erreur AJAX.');
                        }).always(function(){
                            editor.setProgressState(false);
                        });

                    }
                });
            },

            createControl : function(){ return null; }
        });

        tinymce.PluginManager.add('kcc_clean_button', tinymce.plugins.kcc_clean_button);
    }

    // --------------------------------------------------------
    // FALLBACK : mode TEXTE sans TinyMCE
    // --------------------------------------------------------
    $(function(){
        const $content = $('#content');

        if ($content.length && typeof tinymce === 'undefined') {

            const $btn = $('<button type="button" class="button button-secondary" style="margin:6px 0; padding:4px 8px;">' +
                           '<img src="'+KCC.iconUrl+'" width="16" height="16" style="vertical-align:middle; margin-right:4px;">' +
                           'Nettoyer' +
                           '</button>');

            $btn.on('click', function(){
                const original = $content.val();
                $btn.prop('disabled', true).text('Nettoyage…');

                kccAjaxClean(original).done(function(res){
                    if (res && res.success && res.data && typeof res.data.cleaned === 'string') {
                        $content.val(res.data.cleaned);
                    } else {
                        alert('Réponse invalide du serveur.');
                    }
                }).fail(function(){
                    alert('Erreur AJAX.');
                }).always(function(){
                    $btn.prop('disabled', false).html(
                        '<img src="'+KCC.iconUrl+'" width="16" height="16" style="vertical-align:middle; margin-right:4px;">Nettoyer'
                    );
                });
            });

            $('#postdivrich .postarea').first().prepend($btn);
        }
    });

})(jQuery);