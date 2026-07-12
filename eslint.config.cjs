/**
 * ESLint flat config: the @wordpress/scripts default, plus one project override.
 *
 * The admin UI is built with layout primitives that WordPress core still ships
 * only under `__experimental*` names (HStack, VStack, Text, Heading,
 * InputControl). There is no stable public equivalent yet, and this is how the
 * Site Editor itself uses them, so we opt out of the experimental-API rule
 * rather than ship a worse UI. Revisit if/when these are promoted to stable.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		rules: {
			'@wordpress/no-unsafe-wp-apis': 'off',
		},
	},
];
