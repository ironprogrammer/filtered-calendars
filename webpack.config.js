/**
 * Extends the default @wordpress/scripts webpack config.
 *
 * @wordpress/dataviews sets "sideEffects": false in its package.json, which
 * makes webpack tree-shake our side-effect-only stylesheet import out of the
 * production build. Flag that one stylesheet as having side effects so it is
 * kept and extracted into the single compiled build/style-index.css — no
 * separately maintained copy, no extra style handle competing with core.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	module: {
		...defaultConfig.module,
		rules: [
			...defaultConfig.module.rules,
			{
				test: /@wordpress[\\/]dataviews[\\/]build-style[\\/]style\.css$/,
				sideEffects: true,
			},
		],
	},
};
