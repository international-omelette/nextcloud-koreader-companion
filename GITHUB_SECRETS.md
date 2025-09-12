# GitHub Repository Secrets Configuration

This document outlines the secrets required for the automated release pipeline.

## Required Secrets

### `APP_PRIVATE_KEY`
**Purpose**: Private key for signing the Nextcloud app  
**Source**: The private key file generated during the certificate signing request process  
**Format**: Copy the entire content of your `koreader_companion.key` file  
**Path**: Settings → Security → Actions secrets and variables → Repository secrets

### `APPSTORE_TOKEN`
**Purpose**: Authentication token for Nextcloud App Store API  
**Source**: Nextcloud App Store developer portal  
**How to obtain**:
1. Visit https://apps.nextcloud.com/
2. Log in with your developer account
3. Navigate to your developer profile/settings
4. Generate or copy your API token
**Format**: Plain text token string

## Setting Up Secrets

1. Navigate to your GitHub repository
2. Go to Settings → Security → Actions secrets and variables → Repository secrets
3. Click "New repository secret"
4. Add each secret with the exact name specified above

## Security Notes

- **Never commit these secrets to the repository**
- **Use GitHub's encrypted secrets feature only**
- **Rotate tokens periodically for better security**
- **Limit token permissions to minimum required scope**

## Verification

After setting up secrets, you can test the pipeline by:
1. Making a commit with semantic version keywords (`feat:`, `fix:`, etc.)
2. Pushing to the main/master branch
3. Monitoring the Actions tab for workflow execution

## Troubleshooting

### `APP_PRIVATE_KEY` Issues
- Ensure the key is in PEM format
- Include the entire key including `-----BEGIN PRIVATE KEY-----` headers
- Remove any trailing whitespace or newlines

### `APPSTORE_TOKEN` Issues  
- Verify token is active and not expired
- Check token permissions include app publishing rights
- Confirm your developer account has the necessary privileges

## Alternative: Manual Certificate Handling

If you prefer not to store the private key in GitHub secrets, you can:
1. Remove the signing step from the workflow
2. Sign releases manually using the local Docker setup
3. Upload signed tarballs manually to the app store

This reduces automation but provides tighter control over certificate security.

---

*For questions about certificate generation, refer to the Nextcloud App Store submission documentation.*