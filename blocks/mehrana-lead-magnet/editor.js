/**
 * Mehrana Lead Magnet — Gutenberg block editor.
 *
 * Vanilla JS using the wp.* globals (no JSX, no bundler) so the plugin
 * stays a single-zip drop-in. The block stores `token` as a static
 * attribute and emits `<mehrana-cta token="...">` on save — no
 * render_callback, no PHP-side CRM lookup at page render time, so the
 * frontend works even if the CRM is briefly unreachable.
 *
 * Editor flow:
 *  1. On mount, fetch the project's inline-CTA campaigns from the CRM
 *     using the token configured in plugin settings.
 *  2. User picks a campaign from the dropdown -> we store its embed
 *     token plus name/layout for display.
 *  3. Live preview: render <mehrana-cta token="..."> directly in the
 *     editor. The custom-element script is enqueued for the editor
 *     too, so the same element that runs on the published page runs
 *     here — no separate preview pipeline.
 *
 * The plugin settings (CRM URL + project token) are passed in via
 * window.MehranaLeadMagnetBlock, set up by wp_localize_script in PHP.
 */
(function (wp) {
    if (!wp || !wp.blocks || !wp.element) return

    var el = wp.element.createElement
    var Fragment = wp.element.Fragment
    var useState = wp.element.useState
    var useEffect = wp.element.useEffect
    var useRef = wp.element.useRef
    var __ = wp.i18n.__

    var SelectControl = wp.components.SelectControl
    var Spinner = wp.components.Spinner
    var Notice = wp.components.Notice
    var Button = wp.components.Button
    var Placeholder = wp.components.Placeholder
    var ExternalLink = wp.components.ExternalLink

    var useBlockProps = wp.blockEditor.useBlockProps

    var settings = window.MehranaLeadMagnetBlock || {}

    function fetchCampaigns(crmUrl, projectToken) {
        var url = crmUrl.replace(/\/+$/, '') + '/api/public/lead-magnet/project/' + encodeURIComponent(projectToken) + '/inline-list'
        return fetch(url).then(function (r) {
            if (!r.ok) throw new Error('list fetch ' + r.status)
            return r.json()
        })
    }

    function PreviewFrame(props) {
        // The custom-element script is enqueued for the editor in PHP, so
        // the tag is upgraded by the editor iframe's customElements
        // registry. Re-mounting the element on token change is the
        // simplest way to force a fresh render — React's diffing keeps
        // the same DOM node when only an attribute changes, but the
        // element's attributeChangedCallback handles that already. Still,
        // toggling key on parent ensures a clean Shadow DOM lifecycle.
        var token = props.token
        if (!token) return null
        return el('div', {
            className: 'mehrana-lead-magnet-block__preview',
            key: token,
            // Inline the tag via dangerouslySetInnerHTML because React
            // won't render unknown elements with attributes the way we
            // want here — and createElement('mehrana-cta', { token })
            // would set the prop, not the HTML attribute, on some React
            // versions used by older WP.
            dangerouslySetInnerHTML: {
                __html: '<mehrana-cta token="' + token.replace(/"/g, '&quot;') + '"></mehrana-cta>',
            },
        })
    }

    function Edit(props) {
        var attributes = props.attributes
        var setAttributes = props.setAttributes
        var blockProps = useBlockProps({ className: 'mehrana-lead-magnet-block' })

        var loadedRef = useRef(false)
        var stateInit = useState({ status: 'idle', campaigns: [], error: null })
        var state = stateInit[0]
        var setState = stateInit[1]

        useEffect(function () {
            if (loadedRef.current) return
            loadedRef.current = true

            if (!settings.crmUrl || !settings.projectToken) {
                setState({
                    status: 'unconfigured',
                    campaigns: [],
                    error: __('CRM URL and Project Token are not set in plugin settings.', 'mehrana-app'),
                })
                return
            }

            setState({ status: 'loading', campaigns: [], error: null })
            fetchCampaigns(settings.crmUrl, settings.projectToken).then(function (data) {
                var list = (data && data.campaigns) || []
                setState({ status: 'loaded', campaigns: list, error: null })
            }).catch(function (err) {
                setState({
                    status: 'error',
                    campaigns: [],
                    error: err && err.message ? err.message : 'Failed to load campaigns',
                })
            })
        }, [])

        if (state.status === 'unconfigured') {
            return el('div', blockProps,
                el(Placeholder, {
                    icon: 'megaphone',
                    label: __('Mehrana Lead Magnet', 'mehrana-app'),
                    instructions: state.error,
                },
                    el(ExternalLink, {
                        href: settings.settingsUrl || '#',
                    }, __('Open plugin settings', 'mehrana-app'))
                )
            )
        }

        if (state.status === 'loading' || state.status === 'idle') {
            return el('div', blockProps,
                el(Placeholder, { icon: 'megaphone', label: __('Mehrana Lead Magnet', 'mehrana-app') },
                    el(Spinner)
                )
            )
        }

        if (state.status === 'error') {
            return el('div', blockProps,
                el(Placeholder, {
                    icon: 'megaphone',
                    label: __('Mehrana Lead Magnet', 'mehrana-app'),
                    instructions: __('Couldn\'t reach the CRM. Check the URL and Project Token in plugin settings.', 'mehrana-app'),
                },
                    el('p', { style: { fontSize: '12px', opacity: 0.7 } }, state.error),
                    el(Button, {
                        variant: 'secondary',
                        onClick: function () {
                            loadedRef.current = false
                            setState({ status: 'idle', campaigns: [], error: null })
                        },
                    }, __('Retry', 'mehrana-app'))
                )
            )
        }

        var options = [{ label: __('— Select a campaign —', 'mehrana-app'), value: '' }]
            .concat(state.campaigns.map(function (c) {
                return {
                    label: c.name + (c.layout ? ' (' + c.layout + ')' : ''),
                    value: c.token,
                }
            }))

        function onSelect(token) {
            if (!token) {
                setAttributes({ token: '', campaignName: '', campaignLayout: '' })
                return
            }
            var match = state.campaigns.find(function (c) { return c.token === token })
            setAttributes({
                token: token,
                campaignName: match ? match.name : '',
                campaignLayout: match ? (match.layout || '') : '',
            })
        }

        return el('div', blockProps,
            el('div', { className: 'mehrana-lead-magnet-block__controls' },
                el(SelectControl, {
                    label: __('Campaign', 'mehrana-app'),
                    value: attributes.token || '',
                    options: options,
                    onChange: onSelect,
                    help: state.campaigns.length === 0
                        ? __('No inline-CTA campaigns found for this project. Create one in the Mehrana CRM first.', 'mehrana-app')
                        : __('The selected campaign renders at this position on the published page.', 'mehrana-app'),
                })
            ),
            attributes.token
                ? el('div', { className: 'mehrana-lead-magnet-block__preview-wrap' },
                    el('div', { className: 'mehrana-lead-magnet-block__preview-label' },
                        __('Live preview', 'mehrana-app')
                    ),
                    el(PreviewFrame, { token: attributes.token })
                )
                : el(Placeholder, {
                    icon: 'megaphone',
                    label: __('Mehrana Lead Magnet', 'mehrana-app'),
                    instructions: __('Pick a campaign from the dropdown above.', 'mehrana-app'),
                })
        )
    }

    function Save(props) {
        var token = props.attributes.token
        if (!token) return null
        // Static save: emits a real <mehrana-cta> tag into post content.
        // No render_callback — the published page is fully self-contained.
        // The frontend custom-element script (enqueued plugin-side) handles
        // upgrade and rendering.
        return el('mehrana-cta', { token: token })
    }

    wp.blocks.registerBlockType('mehrana/lead-magnet', {
        edit: Edit,
        save: Save,
    })
})(window.wp)
