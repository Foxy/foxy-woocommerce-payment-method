const foxySettings = window.wc.wcSettings.getSetting('foxy_data', {});
const foxyLabel = window.wp.htmlEntities.decodeEntities(foxySettings.title) || window.wp.i18n.__('Foxy for WooCommerce', 'foxy');
const foxyContent = () => {
    return window.wp.htmlEntities.decodeEntities(foxySettings.description || '');
};

// const Label = props => {
//     const { PaymentMethodLabel } = props.components;
//     const icon = <img src={iconUrl} alt={title} name={title} />
//     return <PaymentMethodLabel className='kp-block-label' text={title} icon={icon} />;
// };

const Foxy_Block_Gateway = {
    name: 'foxy',
    label: foxyLabel,
    content: Object(window.wp.element.createElement)(foxyContent, null ),
    edit: Object(window.wp.element.createElement)(foxyContent, null ),
    canMakePayment: () => true,
    ariaLabel: 'label',
    supports: {
        features: foxySettings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod( Foxy_Block_Gateway );
 