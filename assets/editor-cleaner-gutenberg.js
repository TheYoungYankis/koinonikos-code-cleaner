/* global KCC, wp */
(function(){
  const { registerPlugin } = wp.plugins;
  const { PluginDocumentSettingPanel } = wp.editPost;
  const { Button, Notice } = wp.components;
  const { createElement: el, useState } = wp.element;
  const { select, dispatch } = wp.data;

  function cleanAjax(html) {
    return window.jQuery.post(KCC.ajaxUrl, {
      action: 'kcc_clean',
      nonce: KCC.nonce,
      content: html
    });
  }

  const Panel = () => {
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState(null);

    const onClick = () => {
      const content = select('core/editor').getEditedPostContent();
      setBusy(true);
      setMsg(null);
      cleanAjax(content).done((res) => {
        if (res && res.success && res.data && typeof res.data.cleaned === 'string') {
          dispatch('core/editor').editPost({ content: res.data.cleaned });
          setMsg({ status: 'success', text: 'Contenu nettoyé.' });
        } else {
          setMsg({ status: 'error', text: 'Réponse invalide du serveur.' });
        }
      }).fail(() => {
        setMsg({ status: 'error', text: 'Erreur AJAX pendant le nettoyage.' });
      }).always(() => setBusy(false));
    };

    return el(PluginDocumentSettingPanel, { name: 'kcc-panel', title: 'KOINONIKOS — Clean code', className: 'kcc-panel' },
      msg ? el(Notice, { status: msg.status, isDismissible: true }, msg.text) : null,
      el(Button, { isSecondary: true, isBusy: busy, onClick, disabled: busy }, '🧹 Clean code')
    );
  };

  registerPlugin('kcc-cleaner', { render: Panel, icon: null });
})();
