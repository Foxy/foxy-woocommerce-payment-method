## For Developers
1. Make changes to the repository
2. Once you are done with your changes, run the command in plugin folder - `npm run plugin-zip`
3. This will generate a sharable plugin zip file which can be directly installed in wordpress

## WC-Foxy Plugin Installation Guide
1. Install the WC Foxy plugin using the plugin zip file.
2. **Note: Soon this step will be omitted as we will be moving to OAuth flow to generate the credentials instead of manually creating them. Untill then follow the below steps**

   Create admin user in Foxy admin for WC and copy paste the secrets - Client Id, Client Secret, Refresh Token, Access Token
    1. In Foxy admin from left panel go to Settings → Integrations → Get Test Credentials 
    2. Enter Project Name and Project Description and click Create button
    3. Make note of the secrets
    4. From WC admin's left panel go to WooCommerce → Settings → Payments → Foxy 
    5. Enter previously obtained secrets i.e. Client ID, Client Secret, Refresh Token and Access Token. NOTE: Once the secrets have been set and subscriptions have been purchased in a WC Store, these secrets can not be replaced with secrets of any other Foxy Store. Please make sure that the WC Store where you are adding these secrets have never been connected with any other Foxy store before.
    6. Here you can update the default values for Title and Description as well. These will control the Title and Gateway Description which user will see on receipt and payment history and on checkout page
3. Update web receipt settings in Foxy admin.
    1. In Foxy admin from left panel go to Settings → Receipts → Custom template for web receipt
    2. Here you can control the user's redirection back to your WC store and what you want to show him while he is being redirected after successful payment. 
    3. You can use the markup from `web-receipt-template.html` for the starting point. You can edit it to your liking. By default, it will show your store name and logo along with a message "Please wait, redirecting to your receipt...". Please make sure you replace `{{store_domain}}` with your WC store domain.
4. Hide product details on Foxy checkout page (it contains WC Order # and 30 years frequency)
    1. In Foxy admin go to Settings → Cart → Use default layout
    2. Disable all options
5. Disable the email receipts from Foxy
    1. In Foxy admin go to Settings → Receipt → Email receipt
    2. Disable Email receipt option

## When Store URL gets changed

If we for some reason decide to update the store domain/URL, then we will need to update the redirection URL in web receipt which we set in point number 3 above. 

## Change of Foxy store secrets
**Note: Soon this section will be omitted as we will be moving to OAuth flow to generate the credentials instead of manually creating them. Untill then follow the below steps**
This is not at all recommended to change the secrets with that of a new Foxy store. We are currently looking into a way to disable the secret input fields once they have been set. But still if this is really required then we need to follow the below steps in order to update the secrets:
1. Clear all the subscriptions from WC store
2. Clear all the customers from WC store
3. Update the Client secrets by following the steps ininstallation guide i.e. steps through 2.a. till 2.e.


## Notes:
WC plugin in the background will:

1. Set the SSO endpoint to `{wc_site_domain}/index.php?rest_route=/foxy/v1/sso` . If an SSO url is already set for the store, it will get replaced
2. It will add a Json webhook with name as `WC_Store_Transaction_Webhook` and URL `{wc_site_domain}/index.php?rest_route=/foxy/v1/webhook/transaction`
3. In Foxy the subscription frequency would be 30 years. The customer will only get charged whenever the payment is due in WC. If a customer is shifting from non-foxy payment method to Foxy payment method then the start date would be set 30 years in future. 

