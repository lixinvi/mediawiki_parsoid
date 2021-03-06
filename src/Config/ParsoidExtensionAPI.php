<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\Config;

use \Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use Wikimedia\Parsoid\Html2wt\SerializerState;
use Wikimedia\Parsoid\Tokens\DomSourceRange;
use Wikimedia\Parsoid\Tokens\SourceRange;
use Wikimedia\Parsoid\Tokens\Token;
use Wikimedia\Parsoid\Utils\ContentUtils;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMDataUtils;
use Wikimedia\Parsoid\Utils\DOMUtils;
use Wikimedia\Parsoid\Utils\PipelineUtils;
use Wikimedia\Parsoid\Utils\Title;
use Wikimedia\Parsoid\Utils\WTUtils;
use Wikimedia\Parsoid\Wt2Html\Frame;
use Wikimedia\Parsoid\Wt2Html\TT\Sanitizer;

/**
 * Extensions should / will eventually only get access to an instance of this config.
 * Instead of giving them direct access to all of Env, maybe we should given access
 * to specific properties (title, wiki config, page config) and methods as necessary.
 *
 * But, that is post-port TODO when we think more seriously about the extension and hooks API.
 *
 * Extensions are expected to use only these interfaces and strongly discouraged from
 * calling Parsoid code directly. Code review is expected to catch these discouraged
 * code patterns. We'll have to finish grappling with the extension and hooks API
 * to go down this path seriously. Till then, we'll have extensions leveraging existing
 * code as in the native extension code in this repository.
 */
class ParsoidExtensionAPI {
	/** @var Env */
	private $env;

	/** @var Frame */
	private $frame;

	/** @var Token */
	private $extToken;

	/**
	 * @var array TokenHandler options
	 */
	private $wt2htmlOpts;

	/**
	 * @var array Serialiation options / state
	 */
	private $html2wtOpts;

	/**
	 * @var SerializerState
	 */
	private $serializerState;

	/**
	 * @param Env $env
	 * @param array|null $options
	 *  - wt2html: used in wt->html direction
	 *    - frame: (Frame)
	 *    - extToken: (Token)
	 *    - extTag: (string)
	 *    - extTagOpts: (array)
	 *    - inTemplate: (bool)
	 *  - html2wt: used in html->wt direction
	 *    - state: (SerializerState)
	 */
	public function __construct(
		Env $env, array $options = null
	) {
		$this->env = $env;
		$this->wt2htmlOpts = $options['wt2html'] ?? null;
		$this->html2wtOpts = $options['html2wt'] ?? null;
		$this->serializerState = $this->html2wtOpts['state'] ?? null;
		$this->frame = $this->wt2htmlOpts['frame'] ?? null;
		$this->extToken = $this->wt2htmlOpts['extToken'] ?? null;
	}

	/**
	 * @return Env
	 */
	public function getEnv(): Env {
		return $this->env;
	}

	/**
	 * Get an URI for the current page
	 * @return string
	 */
	public function getPageUri(): string {
		$title = Title::newFromText(
			$this->env->getPageConfig()->getTitle(),
			$this->env->getSiteConfig()
		);
		return $this->env->makeLink( $title );
	}

	/**
	 * @return Frame
	 */
	public function getFrame(): Frame {
		return $this->frame;
	}

	/**
	 * Are we parsing in a template context?
	 * @return bool
	 */
	public function inTemplate(): bool {
		return $this->wt2htmlOpts['inTemplate'] ?? false;
	}

	/**
	 * If we are parsing in the context of a parent extension tag,
	 * return the name of that extension tag
	 * @return string|null
	 */
	public function parentExtTag(): ?string {
		return $this->wt2htmlOpts['extTag'] ?? null;
	}

	/**
	 * If we are parsing in the context of a parent extension tag,
	 * return the parsing options set by that tag.
	 * @return array
	 */
	public function parentExtTagOpts(): array {
		return $this->wt2htmlOpts['extTagOpts'] ?? [];
	}

	/**
	 * Return the extTagOffsets from the extToken.
	 * @return DomSourceRange|null
	 */
	public function getExtTagOffsets(): ?DomSourceRange {
		return $this->extToken->dataAttribs->extTagOffsets ?? null;
	}

	/**
	 * @return string
	 */
	public function getExtensionName(): string {
		return $this->extToken->getAttribute( 'name' );
	}

	/**
	 * Return the full extension source
	 * @return string|null
	 */
	public function getExtSource(): ?string {
		if ( $this->extToken->hasAttribute( 'source ' ) ) {
			return $this->extToken->getAttribute( 'source' );
		} else {
			return null;
		}
	}

	/**
	 * Is this extension tag self-closed?
	 * @return bool
	 */
	public function isSelfClosedExtTag(): bool {
		return !empty( $this->extToken->dataAttribs->selfClose );
	}

	/**
	 * @param string $contentId
	 * @return DOMNode
	 */
	public function getContentDOM( string $contentId ): DOMNode {
		return $this->env->getDOMFragment( $contentId )[0];
	}

	/**
	 * @param string $contentId
	 * @return string
	 */
	public function getContentHTML( string $contentId ): string {
		$dom = $this->getContentDOM( $contentId );
		return ContentUtils::toXML( $dom, [ 'innerXML' => true ] );
	}

	/**
	 * Create a parsing pipeline to parse wikitext.
	 *
	 * @param string $wikitext
	 * @param array $parseOpts
	 * - srcOffsets
	 * - frame
	 * - pipelineOpts
	 *   - extTag
	 *   - extTagOpts
	 *   - inlineContext
	 *   - inPHPBlock
	 * @param bool $sol
	 * @return DOMDocument
	 */
	public function parseWikitextToDOM(
		string $wikitext, array $parseOpts, bool $sol
	): DOMDocument {
		$doc = null;
		if ( $wikitext === '' ) {
			$doc = $this->env->createDocument();
		} else {
			// Parse content to DOM and pass DOM-fragment token back to the main pipeline.
			// The DOM will get unwrapped and integrated  when processing the top level document.
			$pipelineOpts = $parseOpts['pipelineOpts'] ?? [];
			$opts = [
				// Full pipeline for processing content
				'pipelineType' => 'text/x-mediawiki/full',
				'pipelineOpts' => [
					'expandTemplates' => true,
					'extTag' => $pipelineOpts['extTag'],
					'extTagOpts' => $pipelineOpts['extTagOpts'] ?? null,
					'inTemplate' => $this->inTemplate(),
					'inlineContext' => !empty( $pipelineOpts['inlineContext'] ),
					// FIXME: Hack for backward compatibility
					// support for extensions that rely on this behavior.
					'inPHPBlock' => !empty( $pipelineOpts['inPHPBlock'] )
				],
				'srcOffsets' => $parseOpts['srcOffsets'] ?? null,
				'sol' => $sol
			];
			$doc = PipelineUtils::processContentInPipeline(
				$this->env,
				$parseOpts['frame'] ?? $this->frame,
				$wikitext,
				$opts
			);
		}
		return $doc;
	}

	/**
	 * @param array $extArgs
	 * @param string $leadingWS
	 * @param string $wikitext
	 * @param array $parseOpts
	 * - srcOffsets
	 * - frame
	 * - wrapperTag
	 * - pipelineOpts
	 *   - extTag
	 *   - extTagOpts
	 *   - inlineContext
	 *   - inPHPBlock
	 * @return DOMDocument
	 */
	public function parseTokenContentsToDOM(
		array $extArgs, string $leadingWS, string $wikitext, array $parseOpts
	): DOMDocument {
		$dataAttribs = $this->extToken->dataAttribs;
		$extTagOffsets = $dataAttribs->extTagOffsets;
		$srcOffsets = new SourceRange(
			$extTagOffsets->innerStart() + strlen( $leadingWS ),
			$extTagOffsets->innerEnd()
		);

		$doc = $this->parseWikitextToDOM(
			$wikitext,
			$parseOpts + [ 'srcOffsets' => $srcOffsets ],
			/* sol */true
		);

		// Create a wrapper and migrate content into the wrapper
		$wrapper = $doc->createElement( $parseOpts['wrapperTag'] );
		$body = DOMCompat::getBody( $doc );
		DOMUtils::migrateChildren( $body, $wrapper );
		$body->appendChild( $wrapper );

		// Sanitize args and set on the wrapper
		$this->sanitizeArgs( $wrapper, $extArgs );

		// Mark empty content DOMs
		if ( $wikitext === '' ) {
			DOMDataUtils::getDataParsoid( $wrapper )->empty = true;
		}

		if ( !empty( $this->extToken->dataAttribs->selfClose ) ) {
			DOMDataUtils::getDataParsoid( $wrapper )->selfClose = true;
		}

		return $doc;
	}

	/**
	 * @param DOMElement $elt
	 * @param array $extArgs
	 */
	public function sanitizeArgs( DOMElement $elt, array $extArgs ): void {
		Sanitizer::applySanitizedArgs( $this->env, $elt, $extArgs );
	}

	/**
	 * Sanitize string to be used as a valid HTML id attribute
	 * @param string $id
	 * @return string
	 */
	public function sanitizeHTMLId( string $id ): string {
		return Sanitizer::escapeIdForAttribute( $id );
	}

	/**
	 * Sanitize string to be used as a CSS value
	 * @param string $css
	 * @return string
	 */
	public function sanitizeCss( string $css ): string {
		return Sanitizer::checkCss( $css );
	}

	/**
	 * Get the list of valid attributes useable for a HTML element
	 * @param string $eltName
	 * @return array
	 */
	public function getValidHTMLAttributes( string $eltName ): array {
		return Sanitizer::attributeWhitelist( $eltName );
	}

	// TODO: Provide support for extensions to register lints
	// from their customized lint handlers.

	/**
	 * Forwards the logging request to the underlying logger
	 * @param mixed ...$args
	 */
	public function log( ...$args ): void {
		$this->env->log( ...$args );
	}

	/**
	 * Extensions might be interested in examining their content embedded
	 * in data-mw attributes that don't otherwise show up in the DOM.
	 *
	 * Ex: inline media captions that aren't rendered, language variant markup,
	 *     attributes that are transcluded. More scenarios might be added later.
	 *
	 * @param DOMElement $elt The node whose data attributes need to be examined
	 * @param Closure $proc The processor that will process the embedded HTML
	 */
	public function processHiddenHTMLInDataAttributes( DOMElement $elt, Closure $proc ): void {
		/* -----------------------------------------------------------------
		 * FIXME: This works but feels special cased, maybe?
		 *
		 * We should also be running DOM cleanup passes on embedded HTML
		 * in data-mw and other attributes.
		 *
		 * See T214994
		 * ----------------------------------------------------------------- */
		// Expanded attributes
		if ( DOMUtils::matchTypeOf( $elt, '/^mw:ExpandedAttrs$/' ) ) {
			$dmw = DOMDataUtils::getDataMw( $elt );
			if ( isset( $dmw->attribs ) && count( $dmw->attribs ) > 0 ) {
				$attribs = &$dmw->attribs[0];
				foreach ( $attribs as &$a ) {
					if ( isset( $a->html ) ) {
						$a->html = $proc( $a->html );
					}
				}
			}
		}

		// Language variant markup
		if ( DOMUtils::matchTypeOf( $elt, '/^mw:LanguageVariant$/' ) ) {
			$dmwv = DOMDataUtils::getJSONAttribute( $elt, 'data-mw-variant', null );
			if ( $dmwv ) {
				if ( isset( $dmwv->disabled ) ) {
					$dmwv->disabled->t = $proc( $dmwv->disabled->t );
				}
				if ( isset( $dmwv->twoway ) ) {
					foreach ( $dmwv->twoway as $l ) {
						$l->t = $proc( $l->t );
					}
				}
				if ( isset( $dmwv->oneway ) ) {
					foreach ( $dmwv->oneway as $l ) {
						$l->f = $proc( $l->f );
						$l->t = $proc( $l->t );
					}
				}
				if ( isset( $dmwv->filter ) ) {
					$dmwv->filter->t = $proc( $dmwv->filter->t );
				}
				DOMDataUtils::setJSONAttribute( $elt, 'data-mw-variant', $dmwv );
			}
		}

		// Inline media -- look inside the data-mw attribute
		if ( WTUtils::isInlineMedia( $elt ) ) {
			$dmw = DOMDataUtils::getDataMw( $elt );
			$caption = $dmw->caption ?? null;
			if ( $caption ) {
				$dmw->caption = $proc( $caption );
			}
		}
	}

	/**
	 * Bit flags describing escaping / serializing context in html -> wt mode
	 */
	const IN_SOL = 1;
	const IN_MEDIA = 2;
	const IN_LINK = 4;
	const IN_IMG_CAPTION = 8;
	const IN_OPTION = 16;

	/**
	 * FIXME: We should get rid of this and simply let RT tests fail on this or add
	 * other test output normalizations to deal with it. But, this should be done
	 * as a separate refactoring step to isolate its affects and reset the rt test baseline.
	 * @return bool
	 */
	public function rtTestMode(): bool {
		return $this->serializerState->rtTestMode;
	}

	/**
	 * FIXME: This is a bit broken - shouldn't be needed ideally
	 * @param string $flag
	 */
	public function setHtml2wtStateFlag( string $flag ) {
		$this->serializerState->{$flag} = true;
	}

	/**
	 * Convert the HTML body of an extension to wikitext
	 *
	 * @param array $opts
	 *  - extName: (string) Name of the extension whose body we are serializing
	 *  - inPHPBlock: (bool) FIXME: This needs to be removed
	 * @param string $html HTML for the extension's body
	 * @return mixed // FIXME: Don't want to expose ConstrainedText object
	 */
	public function serializeHTML( array $opts, string $html ) {
		// Type cast so phan has more information to ensure type safety
		$state = $this->serializerState;
		$opts['env'] = $this->env;
		return $state->serializer->serializeHTML( $opts, $html );
	}

	/**
	 * Emit the opening tag (including attributes) for the extension
	 * represented by this node.
	 *
	 * @param DOMElement $node
	 * @return string
	 */
	public function serializeExtensionStartTag( DOMElement $node ): string {
		// Type cast so phan has more information to ensure type safety
		$state = $this->serializerState;
		return $state->serializer->serializeExtensionStartTag( $node, $state );
	}

	/**
	 * @param DOMElement $elt
	 * @param int $context OR-ed bit flags specifying escaping / serialization context
	 * @param bool $singleLine Should the output be in a single line?
	 *   If so, all embedded newlines will be dropped. Ex: list content has this constraint.
	 * @return string
	 */
	public function serializeChildren( DOMElement $elt, int $context, bool $singleLine ): string {
		$state = $this->serializerState;
		if ( $singleLine ) {
			$state->singleLineContext->enforce();
		}
		if ( $context & self::IN_IMG_CAPTION ) {
			if ( $context & self::IN_OPTION ) {
				$escapeHandler = 'mediaOptionHandler'; // Escapes "|" as well
			} else {
				$escapeHandler = 'wikilinkHandler'; // image captions show up in wikilink syntax
			}
			$out = $state->serializeCaptionChildrenToString( $elt,
				[ $state->serializer->wteHandlers, $escapeHandler ] );
		} else {
			throw new \RuntimeException( 'Not yet supported!' );
		}
		if ( $singleLine ) {
			$state->singleLineContext->pop();
		}

		return $out;
	}

	/**
	 * Escape any wikitext like constructs in a string so that when the output
	 * is parsed, it renders as a string. The escaping is sensitive to the context
	 * in which the string is embedded. For example, a "*" is not safe at the start
	 * of a line (since it will parse as a list item), but is safe if it is not in
	 * a start of line context. Similarly the "|" character is safe outside tables,
	 * links, and transclusions.
	 *
	 * @param string $str
	 * @param DOMNode $node
	 * @param int $context OR-ed bit flags specifying escaping / serialization context
	 * @return string
	 */
	public function escapeWikitext( string $str, DOMNode $node, int $context ): string {
		if ( $context & ( self::IN_MEDIA | self::IN_LINK ) ) {
			$state = $this->serializerState;
			return $state->serializer->wteHandlers->escapeLinkContent(
				$state, $str,
				(bool)( $context & self::IN_SOL ),
				$node,
				(bool)( $context & self::IN_MEDIA )
			);
		} else {
			throw new \RuntimeException( 'Not yet supported!' );
		}
	}
}
