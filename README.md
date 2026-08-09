# Therius Payment Orchestration for WooCommerce

This plugin integrates the Therius Payment Orchestration Platform with your WooCommerce store, allowing you to accept secure, PCI-compliant payments while taking advantage of smart routing and multi-PSP capabilities.

## Installation

### Manual Installation (Zip File)
1. Zip the `therius-plugin-woocommerce` folder.
2. Log into your WordPress admin dashboard.
3. Navigate to **Plugins > Add New** and click **Upload Plugin** at the top.
4. Select the `.zip` file you created and click **Install Now**.
5. Once installed, click **Activate Plugin**.

### Manual Installation (FTP / File System)
1. Clone or copy the `therius-plugin-woocommerce` folder into your WordPress installation's `wp-content/plugins/` directory.
2. Log into your WordPress admin dashboard.
3. Navigate to **Plugins** and find "Therius Payment Orchestration" in the list.
4. Click **Activate**.

## Configuration & Environments (Sandbox / Production)

Yes, the plugin fully supports both **Sandbox (Test)** and **Production (Live)** environments out of the box!

1. Navigate to **WooCommerce > Settings > Payments**.
2. Find **Therius Payments** in the list and click **Manage**.
3. Check the **Enable Therius Payments** box.
4. **Environment Setup:**
   - **Sandbox/Test Mode:** Check the "Enable Test Mode" box. Enter your Therius Sandbox keys into the **Test Publishable Key** and **Test Private Key** fields. When test mode is active, the plugin automatically routes transactions to the Sandbox API (`https://api-sandbox.therius.io`).
   - **Production/Live Mode:** Uncheck the "Enable Test Mode" box. Enter your live Therius keys into the **Live Publishable Key** and **Live Private Key** fields. Transactions will now be securely routed to the live production environment (`https://api.therius.io`).
5. Click **Save changes** at the bottom of the page.

## Webhooks (required for reliable order status)

The checkout call to `/payment/purchase` tells you the outcome at the moment of charge, but capture
confirmation, refunds, cancellations, and chargebacks all happen **after** that response — the plugin
only learns about them if you configure a webhook.

1. In the Therius Dashboard, go to **Developers → Webhooks** and add an endpoint pointing at:
   ```
   https://your-store.com/?wc-api=WC_Gateway_Therius
   ```
   (This exact URL is also shown on the plugin's settings page.) Configure one webhook in **Sandbox**
   mode and one in **Production** mode if you use both.
2. Copy the signing secret shown for each environment into the plugin's **Test Webhook Signing Secret**
   / **Live Webhook Signing Secret** fields.
3. Subscribe to at least: `payment.captured`, `payment.refused`, `payment.refunded`, `payment.cancelled`,
   `payment.chargeback`.

Without this configured, orders that are only `authorized` (not immediately captured) will sit in
**On hold** indefinitely, and refunds/chargebacks issued from the Therius dashboard won't be reflected
on the WooCommerce order. Every incoming webhook call is verified against the signing secret
(`X-Therius-Signature`, HMAC-SHA256) before anything is applied — unsigned or mis-signed requests are
rejected with `401`.

You are now ready to accept payments via Therius!
