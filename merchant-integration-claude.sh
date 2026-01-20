#!/bin/bash
set -e


# Color definitions
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Version requirements
MIN_NODE_VERSION=18
MIN_CLAUDE_VERSION="2.0.20"
NODE_INSTALL_VERSION=22

print_info() {
    echo -e "${BLUE} $1${NC}"
}
print_success() {
    echo -e "${GREEN} $1${NC}"
}
print_warning() {
    echo -e "${YELLOW} $1${NC}"
}
print_error() {
    echo -e "${RED} $1${NC}"
}
print_error "You must run this script in your project directory !!!"
print_info "current directory: `pwd`"


BASE_URL="http://0.0.0.0:8080/byoa/default_app/mcp/"
CONFIG_FILE="merchant-integration.env"


cleanup_and_exit() {
    rm -rf .claude
    echo "" && echo ""
    print_warning "Removed existing .claude directory"
    exit 1
}

trap cleanup_and_exit SIGINT SIGTSTP


command_exists() {
    command -v "$1" >/dev/null 2>&1
}

version_greater_than_or_equal_to() {
    command awk 'BEGIN {
        if (ARGV[1] == "" || ARGV[2] == "") exit(1)
        split(ARGV[1], a, /\./);
        split(ARGV[2], b, /\./);
        for (i=1; i<=3; i++) {
            if (a[i] && a[i] !~ /^[0-9]+$/) exit(2);
            if (a[i] < b[i]) exit(3);
            else if (a[i] > b[i]) exit(0);
        }
        exit(0)
    }' "${1#v}" "${2#v}"
}

install_git() {
    if command_exists git; then
        print_success "git already installed, version: $(git --version)"
        return 0
    fi

    print_warning "git not found, installing..."
    if command_exists brew; then
        brew install git
    else
        print_info "Please refer to https://git-scm.com/downloads for git installation and re-run this script"
        exit 1
    fi

    if command_exists git; then
        print_success "git installed successfully, version: $(git --version)"
    else
        print_error "git installation failed"
        exit 1
    fi
}

configure_git() {
    # Check git user.name
    current_name=$(git config --global user.name 2>/dev/null || echo "")
    if [[ -z "$current_name" ]]; then
        git config --global user.name "merchant-toolkit"
        print_success "git global user.name set: merchant-toolkit"
    else
        print_success "git global user.name already configured: $current_name"
    fi

    # Check git user.email
    current_email=$(git config --global user.email 2>/dev/null || echo "")
    if [[ -z "$current_email" ]]; then
        git config --global user.email "merchant-toolkit"
        print_success "git global user.email set: merchant-toolkit"
    else
        print_success "git global user.email already configured: $current_email"
    fi
}

load_nvm() {
    if [ -s "$HOME/.nvm/nvm.sh" ]; then
        source "$HOME/.nvm/nvm.sh"
    fi
    if [ -s "$HOME/.nvm/bash_completion" ]; then
        source "$HOME/.nvm/bash_completion"
    fi
}

install_nvm() {
    load_nvm
    # Check if nvm function is available (nvm is a shell function, not a command)
    if type nvm >/dev/null 2>&1; then
        print_success "nvm already installed, version: $(nvm --version)"
        return 0
    fi

    print_warning "nvm not found, installing..."

    # Try primary mirror (GitHub)
    if curl -k -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash; then
        print_success "nvm installed from GitHub mirror"
    else
        print_warning "GitHub mirror failed, trying Gitee mirror..."
        # Try fallback mirror (Gitee)
        export NVM_SOURCE="https://gitee.com/mirrors/nvm.git"
        export NVM_INSTALL_GITHUB_REPO="mirrors/nvm"
        export NVM_INSTALL_VERSION="v0.40.3"
        if curl -k -o- https://gitee.com/mirrors/nvm/raw/v0.40.3/install.sh | bash; then
            print_success "nvm installed from Gitee mirror"
        else
            print_error "nvm installation failed from both mirrors"
            exit 1
        fi
    fi

    load_nvm
    if type nvm >/dev/null 2>&1; then
        print_success "nvm installed successfully, version: $(nvm --version)"
        return 0
    else
        print_error "nvm installation verification failed"
        exit 1
    fi
}

install_nodejs() {
    load_nvm

    # Check if current version is suitable (>= 18)
    current_version=$(nvm current 2>/dev/null || echo "none")
    if [[ "$current_version" != "none" ]] && version_greater_than_or_equal_to "$current_version" "v$MIN_NODE_VERSION"; then
        print_success "node.js $current_version already active and suitable"
        return 0
    fi

    # Current version not suitable, check if v22 is installed
    print_info "Checking if node.js v$NODE_INSTALL_VERSION is installed..."
    if nvm ls $NODE_INSTALL_VERSION >/dev/null 2>&1; then
        print_info "Found node.js v$NODE_INSTALL_VERSION, switching to it..."
        nvm use $NODE_INSTALL_VERSION
        print_success "Switched to node.js $(nvm current)"
    else
        print_warning "node.js v$NODE_INSTALL_VERSION not found, installing..."
        nvm install v$NODE_INSTALL_VERSION
        nvm use v$NODE_INSTALL_VERSION
        print_success "node.js v$NODE_INSTALL_VERSION installed successfully, version: $(nvm current)"
    fi
}

get_node_bin_dir() {
    load_nvm
    NODE_PATH="$(nvm which current)"
    BIN_DIR="$(dirname "$NODE_PATH")"
    echo "$BIN_DIR"
}

get_claude_path() {
    command -v claude 2>/dev/null || true
}

get_npm_path() {
    BIN_DIR="$(get_node_bin_dir)"
    echo "$BIN_DIR/npm"
}

check_claude_version() {
    if ! command_exists claude; then
        print_warning "claude command not found in PATH"
        return 1
    fi

    CLAUDE_PATH="$(get_claude_path)"
    CLAUDE_VERSION=$(claude --version 2>/dev/null | head -n 1 | awk '{print $1}')

    if [ -z "$CLAUDE_VERSION" ]; then
        print_warning "unable to determine claude version"
        return 1
    fi

    if version_greater_than_or_equal_to "$CLAUDE_VERSION" "$MIN_CLAUDE_VERSION"; then
        print_success "$CLAUDE_PATH installed with version: $CLAUDE_VERSION"
        return 0
    else
        print_warning "$CLAUDE_PATH found but version $CLAUDE_VERSION is less than required $MIN_CLAUDE_VERSION"
        return 1
    fi
}

install_claude() {
    if check_claude_version; then
        return 0
    fi

    print_warning "Installing Claude Code..."

    NPM_PATH="$(get_npm_path)"

    # Configure npm
    "$NPM_PATH" set strict-ssl false

    # Try default registry first
    print_info "Installing claude code from default npm registry..."
    if ! "$NPM_PATH" install -g @anthropic-ai/claude-code; then
        print_warning "Default registry failed, switching to mirror..."

        # Try Chinese mirror
        "$NPM_PATH" config set registry https://registry.npmmirror.com
        if ! "$NPM_PATH" install -g @anthropic-ai/claude-code; then
            print_error "Failed to install Claude Code from both registries"
            exit 1
        fi
    fi

    # Verify installation
    if check_claude_version; then
        return 0
    else
        print_error "Claude Code installation verification failed"
        exit 1
    fi
}

generate_config() {    
    if [ -f "$CONFIG_FILE" ]; then
        print_warning "$CONFIG_FILE already exists"
        echo -n " Do you want to overwrite it? (y/N): "
        read -r overwrite
        if [[ ! "$overwrite" =~ ^[Yy]$ ]]; then
            print_info "Displaying existing config:\n"
            cat "$CONFIG_FILE" | grep -v "^#"
            return 0
        fi
    fi
    
    cat > "$CONFIG_FILE" << 'EOF'
# There are 2 ways to use LLM:
# 1. Sign in with your Anthropic account using /login in ClaudeCode
# 2. Use API-based authentication by filling in the following fields:

ANTHROPIC_DEFAULT_SONNET_MODEL=claude-sonnet-4-5-20250929
ANTHROPIC_DEFAULT_HAIKU_MODEL=claude-sonnet-4-5-20250929
# ANTHROPIC_BASE_URL=your_llm_endpoint
# ANTHROPIC_AUTH_TOKEN=your_api_key

# You can modify the above fields based on your actual needs

# This token is for downloading resources, please get from pp team
AIM_TOKEN=your_aim_token
EOF

    if [ -f "$CONFIG_FILE" ]; then
        print_success "$CONFIG_FILE generated successfully in the current project root directory\n"
        print_success "Config file has been configured with default models"
        print_info "You can edit $CONFIG_FILE if you need to use different models"
    else
        print_error "fail to generate $CONFIG_FILE"
        exit 1
    fi
}


init_config() {
    print_info "1. Init Config"
    generate_config
}


update_gitignore() {
    local gitignore_file=".gitignore"
    
    # Check if .gitignore exists, create if not
    if [ ! -f "$gitignore_file" ]; then
        touch "$gitignore_file"
    fi
    
    echo "" >> "$gitignore_file"
    # Append patterns if not exists
    grep -q "^\.claude/" "$gitignore_file" 2>/dev/null || echo ".claude/" >> "$gitignore_file"
    grep -q "^claude-\*/" "$gitignore_file" 2>/dev/null || echo "claude-*/" >> "$gitignore_file"
}

install_tool() {
    print_info "2. Install Tool"

    # Check if config exists
    if [ ! -f "$CONFIG_FILE" ]; then
        print_error "$CONFIG_FILE not found!"
        print_warning "please run 'Init Config' to generate config"
        return 0
    fi

    install_git
    configure_git

    load_nvm
    # Check if claude already exists
    if check_claude_version; then
        print_info "Claude Code already available, skipping installation"
        return 0
    fi

    # Claude not available or version insufficient, install Node.js and Claude
    print_info "Installing Claude Code..."
    install_nvm
    install_nodejs
    install_claude
}

setup_claude() {
    print_info "Setting up Claude resources..."

    # Download zip file and capture HTTP status code
    ZIP_FILE="claude.zip"
    rm -f "$ZIP_FILE"

    HTTP_CODE=$(curl -k -w "%{http_code}" -o "$ZIP_FILE" \
        --header "token: $AIM_TOKEN" \
        "${BASE_URL}claude/download")

    if [ "$HTTP_CODE" != "200" ]; then
        print_error "[HTTP: $HTTP_CODE] Failed to download Claude resources, Please check AIM_TOKEN in $CONFIG_FILE"
        exit 1
    fi
    if [ ! -f "$ZIP_FILE" ]; then
        print_error "Failed to save downloaded file"
        exit 1
    fi
    print_success "Downloaded $ZIP_FILE"
    
    # Remove existing .claude directory
    if [ -d ".claude" ]; then
        rm -rf .claude
        print_warning "Removed existing .claude directory"
    fi
    # Rename existing claude directory
    if [ -d "claude" ]; then
        TIMESTAMP=$(date +%Y%m%d%H%M%S)
        mv claude "claude-$TIMESTAMP"
        print_warning "Renamed existing claude directory to claude-$TIMESTAMP"
    fi

    # Unzip and rename
    unzip -q "$ZIP_FILE"
    print_success "Extracted $ZIP_FILE"
    mv claude .claude
    print_success "Claude resources updated to .claude directory"

    # Update .gitignore
    update_gitignore

    # Clean up zip file
    rm -f "$ZIP_FILE"
}

start_claude() {
    print_info "3. Start Claude"

    # Check if config exists
    if [ ! -f "$CONFIG_FILE" ]; then
        print_error "$CONFIG_FILE not found!"
        print_warning "please run 'Init Config' to generate config"
        return 0
    fi

    # Load and export environment variables
    print_info "loading environment variables from $CONFIG_FILE..."
    while IFS='=' read -r key value; do
        if [[ -n $value && $value != "your_"* ]]; then
            export "$key=$value"
            print_success "export $key=$value"
        else
            print_warning "$key not set or using default placeholder value, please edit $CONFIG_FILE"
            exit 1
        fi
    done < <(cat "$CONFIG_FILE" | grep -v "#" | grep -E "ANTHROPIC_|AIM_TOKEN")

    export NODE_NO_WARNINGS=1
    export NODE_TLS_REJECT_UNAUTHORIZED=0

    # Load nvm and get claude path
    load_nvm
    CLAUDE_PATH="$(get_claude_path)"

    # check claude
    if [ ! -f "$CLAUDE_PATH" ]; then
        print_error "$CLAUDE_PATH not found!"
        print_warning "please run 'Install Tool' to install claude"
        return 0
    fi

    echo ""
    setup_claude

    print_success "starting $CLAUDE_PATH, current directory: `pwd`"
    echo ""
    sleep 2
    "$CLAUDE_PATH" --append-system-prompt "Implement directly. Never ask for user confirmation."
}


# Display menu
show_menu() {
    echo " ========================================="
    echo " Merchant Integration Tools"
    echo " 1. Init Config"
    echo " 2. Install Tool"
    echo " 3. Start Claude"
    echo " 0. Exit"
    echo " ========================================="
    echo -n " Please Enter Option [1,2,3,0]: "
}

# Main loop
main() {
    while true; do
        echo "" && echo ""
        show_menu && read -r choice
        echo "" && echo ""
        
        case $choice in
            1)
                init_config
                ;;
            2)
                install_tool
                ;;
            3)
                start_claude
                ;;
            0)
                rm -rf .claude
                print_warning "Removed existing .claude directory"
                exit 0
                ;;
            *)
                print_warning "Invalid option, please enter option [1,2,3,0]"
                ;;
        esac
    done
}


main