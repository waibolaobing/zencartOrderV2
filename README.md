# Zencart Development Environment (PayPal Orders v2 API)

This directory contains a Zencart development environment configured with Docker.**This environment is pre-configured with PayPal Orders v2 API integration.**

## Prerequisites

Ensure Docker is installed. If not installed, search for "rancher" in Self Service and install Rancher Desktop.

## Available Management Scripts

### `./start.sh` - Complete Startup

Starts containers and initializes the project (recommended for first-time setup)

### `./reset.sh` - Stop Containers and Reset Code

Stops all running containers and resets code to initial state. After running this script, use `./start.sh` to restart the project.

## Setup Steps

1. Start copilot-api. For detailed steps, refer to: [Integrate Claude-Code with github copilot](https://paypal.atlassian.net/wiki/spaces/PCT/pages/2622464803/Integrate+Claude-Code+with+github+copilot)

2. Run `./start.sh` and wait for installation to complete. Once installation completes, the zencart orderv2 project is successfully started.

3. Configure Claude tooling by running (If you need to convert code, please continue this step):

   ```bash
   curl -fsSL "https://api.staging.paypal.cn/mcp/script/claude.sh" -o setup.sh && chmod +x setup.sh && ./setup.sh
   ```

4. Select `1` at the prompt screen, generate the configuration file `merchant-integration.env` in the `zencart-code` directory, edit the file with the following content and replace ANTHROPIC_AUTH_TOKEN:

   ```
   ANTHROPIC_BASE_URL=http://localhost:4141
   ANTHROPIC_AUTH_TOKEN=dummy
   ANTHROPIC_MODEL=claude-sonnet-4.5
   ANTHROPIC_SMALL_FAST_MODEL=claude-sonnet-4.5
   ```

5. Select `2` to install required dependencies.
6. Select `3` to start Claude Code. In Claude Code, enter `/model` command to check if the claude-sonnet-4-5-20250929 model is successfully connected; enter `/mcp` command to check if pp-converter is successfully connected.
7. If all above steps complete without errors, visit [http://localhost:8087/](http://localhost:8087/) in your browser to access the zencart website.
8. Execute PayPal API conversion. Run the first two commands in Claude Code. (If Claude Code is closed, restart it with the command `claude --dangerously-skip-permissions`)

**📋 After PayPal API Conversion Completes**

- **You should review the changes** using the following commands:
  ```bash
  cd code
  git status                      # Check what files were modified
  git diff                        # Review the actual changes
  ```
- This workflow lets you see exactly what AI modified before committing changes

```bash
# 1. 🛠️ Environment Check
execute init_check mcp tool

# 2. 🛠️ Execute Conversion (requires PayPal API credentials)
execute default_converter mcp tool with
client_id YOUR_CLIENT_ID
secret YOUR_SECRET

# 3. 🛠️ Execute Correction if errors occur (max 3 times, run FT test after each) (Optional)
execute correction mcp tool

# 4. 🛠️ Revert if needed (automatically executed after 3 correction attempts) (Optional)
execute revert mcp tool
```

9. After step 8 completes, the conversion to PayPal REST v2 API is complete. Open [http://localhost:8087/](http://localhost:8087/) to test the following five use cases. If you encounter payment errors during checkout, execute `execute correction mcp tool` in Claude Code to fix, then test again.

10. If all the above steps and test cases pass, one round of testing is complete. To start a new round of testing, run `./reset.sh` to stop containers and reset the zencart project, then continue from step 2..

11. When all testing is complete, run `./reset.sh` to stop all containers and reset code.

## Test Cases

There are five test cases in total:

1. **Standard Checkout Flow**

   - Step 1: Visit [http://localhost:8087](http://localhost:8087), select any product
   - Step 2: Click Add to Cart to add to shopping cart
   - Step 3: Click Checkout, enter registered email and password to login (Email: test@paypal.com, Password: test123)
   - Step 4: Click Continue, select Checkout with PayPal in Payment Method and continue
   - Step 5: Click Confirm Order, when prompted "Login in with a one-time code" enter 111111
   - Step 6: Click Complete Purchase, "Thank You! We Appreciate your Business!" message indicates successful order

2. **PayPal Express Checkout**

   - Step 1: Visit [http://localhost:8087](http://localhost:8087), select any product
   - Step 2: Click Add to Cart to add to shopping cart
   - Step 3: Click Check Out with PayPal, enter PayPal email and password to login
   - Step 4: Click Continue to Review Order and continue
   - Step 5: Click Confirm Order to complete payment, "Thank You! We Appreciate your Business!" message indicates successful order

3. **Refund Flow**

   - Step 1: Visit [http://localhost:8087/admin_secure](http://localhost:8087/admin_secure), login with Username: admin, Password: admin123
   - Step 2: Click Customers => Orders to view orders
   - Step 3: Select an order, click Edit, then click "Click for Additional Payment Handling Options", check Confirm next to "Do Full Refund" and click the button
   - Step 4: "PayPal refund for XXX initiated" message indicates successful refund

4. **Coupon Test**

   - Step 1: Open [http://localhost:8087/admin_secure](http://localhost:8087/admin_secure), enter Username: admin, Password: admin123, click Submit to login
   - Step 2: Click Discounts => Coupon Admin then click insert to add a new coupon. Configure the coupon as follows:  
     Coupon Name: Test Coupon  
     Coupon Amount: 10  
     Coupon Minimum Order: 0  
     Coupon Minimum calculated from: All Products  
     Coupon Code: C0001  
     Uses per Coupon: 100  
     Uses per Customer: 100  
     Click preview, then click Confirm to complete.
   - Step 3: Open [http://localhost:8087](http://localhost:8087) and select a product, proceed with checkout using either the standard checkout flow or PayPal express checkout flow above. During checkout, enter the coupon code C0001 in the Discount Coupon field. If you see "Congratulations you have redeemed the Discount Coupon" and the final amount successfully deducts the coupon value, the coupon was applied successfully. Complete the order checkout.

5. **Shipping Fee Test**

   - Step 1: Open [http://localhost:8087/admin_secure](http://localhost:8087/admin_secure), enter Username: admin, Password: admin123, click Submit to login
   - Step 2: Open [http://localhost:8087](http://localhost:8087), in the Documents category, select a virtual product (file type) valued over $10 USD for checkout. During checkout, enter coupon code C0001 in the Discount Coupon field. If the final amount is 0, the test is successful
   - Step 3: Return to [http://localhost:8087](http://localhost:8087), follow the same steps as Step 3 to order a physical product valued over $10 USD. During checkout, enter coupon code C0001 in the Discount Coupon field. If you see the product price reduced by 10 with only shipping fee remaining, the test is successful

## Conversion Verification Criteria

During the payment step, you can determine if the conversion was successful by checking the PayPal redirect URL:

- If the URL looks like  
  `https://www.sandbox.paypal.com/checkoutnow/?cmd=_express-checkout&token=EC-7PA56891RR758872L&useraction=commit`  
  (token=EC-XXX), this indicates the conversion was unsuccessful and it's still using the legacy interface.
- If the URL looks like  
  `https://www.sandbox.paypal.com/checkoutnow/?cmd=_express-checkout&token=9J502328ME3025801&useraction=commit`  
  (token without EC- prefix), this indicates successful conversion to the new PayPal REST v2 API.

---

## Git Tracking Management

**🗂️ Git Tracking**: All AI modifications are tracked in an independent Git repository within the `code` directory:

- **Default tracked files**: `includes/modules/payment/` (PayPal payment modules)
- **Add custom files**: Use `git add <file>` to track additional files as needed
- **Customize tracking**: Edit `default_gitignore` to modify Git tracking rules
  - **Apply changes**: If services are running, stop them first (`docker-compose down`), then restart with `./start.sh`
- Initial baseline code is committed automatically

---

## ⚠️ Important Notes

- **🛠️ correction MCP Tool** is limited to 3 attempts, FT testing is performed after each execution, and the process ends upon success
- If FT still fails after 3 correction attempts, the system will automatically execute **🛠️ revert MCP Tool** to rollback
- All conversions create Git backup points to ensure code safety
- Supports sandbox and production environments, please configure environment parameters correctly
- Valid PayPal REST API credentials (Client ID and Secret) are required
