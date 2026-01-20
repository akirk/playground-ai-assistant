(function($) {
    'use strict';

    var STORAGE_PREFIX = 'aiAssistant_';

    // Ensure namespace exists (this script may load before chat-core.js)
    window.aiAssistant = window.aiAssistant || {};

    $.extend(window.aiAssistant, {
        getSetting: function(key) {
            try {
                return localStorage.getItem(STORAGE_PREFIX + key);
            } catch (e) {
                console.warn('[AI Assistant] localStorage not available:', e);
                return null;
            }
        },

        setSetting: function(key, value) {
            try {
                if (value === null || value === undefined) {
                    localStorage.removeItem(STORAGE_PREFIX + key);
                } else {
                    localStorage.setItem(STORAGE_PREFIX + key, value);
                }
                return true;
            } catch (e) {
                console.warn('[AI Assistant] Failed to save setting:', e);
                return false;
            }
        },

        removeSetting: function(key) {
            try {
                localStorage.removeItem(STORAGE_PREFIX + key);
                return true;
            } catch (e) {
                return false;
            }
        },

        getProvider: function() {
            return this.getSetting('provider') || 'anthropic';
        },

        setProvider: function(provider) {
            return this.setSetting('provider', provider);
        },

        getModel: function() {
            return this.getSetting('model') || '';
        },

        setModel: function(model) {
            return this.setSetting('model', model);
        },

        getSummarizationModel: function() {
            return this.getSetting('summarizationModel') || '';
        },

        setSummarizationModel: function(model) {
            return this.setSetting('summarizationModel', model);
        },

        getApiKey: function(provider) {
            provider = provider || this.getProvider();
            if (provider === 'anthropic') {
                return this.getSetting('anthropicApiKey') || '';
            } else if (provider === 'openai') {
                return this.getSetting('openaiApiKey') || '';
            }
            return '';
        },

        setApiKey: function(provider, key) {
            if (provider === 'anthropic') {
                return this.setSetting('anthropicApiKey', key);
            } else if (provider === 'openai') {
                return this.setSetting('openaiApiKey', key);
            }
            return false;
        },

        getLocalEndpoint: function() {
            return this.getSetting('localEndpoint') || 'http://localhost:11434';
        },

        setLocalEndpoint: function(endpoint) {
            return this.setSetting('localEndpoint', endpoint);
        },

        getLocalModel: function() {
            return this.getSetting('localModel') || '';
        },

        setLocalModel: function(model) {
            return this.setSetting('localModel', model);
        },

        isConfigured: function() {
            var provider = this.getProvider();
            if (provider === 'local') {
                return true;
            }
            var apiKey = this.getApiKey(provider);
            return apiKey && apiKey.length > 0;
        },

        getAllSettings: function() {
            return {
                provider: this.getProvider(),
                model: this.getModel(),
                summarizationModel: this.getSummarizationModel(),
                anthropicApiKey: this.getSetting('anthropicApiKey') ? '***' + this.getSetting('anthropicApiKey').slice(-4) : '',
                openaiApiKey: this.getSetting('openaiApiKey') ? '***' + this.getSetting('openaiApiKey').slice(-4) : '',
                localEndpoint: this.getLocalEndpoint(),
                localModel: this.getLocalModel()
            };
        },

        clearAllSettings: function() {
            var keys = ['provider', 'model', 'summarizationModel', 'anthropicApiKey', 'openaiApiKey', 'localEndpoint', 'localModel'];
            var self = this;
            keys.forEach(function(key) {
                self.removeSetting(key);
            });
        }
    });

})(jQuery);
