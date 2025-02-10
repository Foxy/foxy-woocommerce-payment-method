const foxySettings = window.wc.wcSettings.getSetting('foxy_data', {});
const foxyLabel = window.wp.htmlEntities.decodeEntities(foxySettings.title) || window.wp.i18n.__('Foxy for WooCommerce', 'foxy');
const foxyContent = () => {
    return window.wp.htmlEntities.decodeEntities(foxySettings.description || '');
};

const icon = foxySettings.icon_url;
wpElement = window.wp.element;

const Foxy_Block_Gateway = {
    name: 'foxy',
    label: wpElement.createElement(() =>
        wpElement.createElement(
          "div",
          {
            style:{display: 'flex', 'justify-content': 'space-between', width: '98%'}
          },
          wpElement.createElement("span", null, foxyLabel),
          wpElement.createElement("img", {
            src: icon,
            alt: foxyLabel,
          })
        )
      ),
    content: Object(window.wp.element.createElement)(foxyContent, null ),
    edit: Object(window.wp.element.createElement)(foxyContent, null ),
    canMakePayment: () => true,
    ariaLabel: 'label',
    supports: {
        features: foxySettings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod( Foxy_Block_Gateway );
 