/**
 * @license Copyright (c) 2014-2026, CKSource Holding sp. z o.o. All rights reserved.
 * For licensing, see LICENSE.md or https://ckeditor.com/legal/ckeditor-licensing-options
 */

import * as CKEditor from 'ckeditor5';
import {
	Alignment,
	AutoLink,
	Autoformat,
	Base64UploadAdapter,
	BlockQuote,
	Bookmark,
	Bold,
	ButtonView,
	ClassicEditor,
	CloudServices,
	Code,
	CodeBlock,
	Essentials,
	Emoji,
	FontBackgroundColor,
	FontColor,
	FontFamily,
	FontSize,
	FindAndReplace,
	Fullscreen,
	GeneralHtmlSupport,
	Heading,
	Highlight,
	HorizontalLine,
	HtmlEmbed,
	Image,
	ImageCaption,
	ImageResize,
	ImageStyle,
	ImageToolbar,
	ImageUpload,
	Indent,
	IndentBlock,
	Italic,
	Link,
	LinkImage,
	List,
	ListProperties,
		Markdown,
	MediaEmbed,
	Mention,
	PageBreak,
		PasteFromMarkdownExperimental,
	Paragraph,
	PasteFromOffice,
	PlainTableOutput,
	Plugin,
	RemoveFormat,
	SourceEditing,
	SpecialCharacters,
	SpecialCharactersEssentials,
	ShowBlocks,
	Strikethrough,
	Style,
	Subscript,
	Superscript,
	Table,
	TableCaption,
	TableCellProperties,
	TableColumnResize,
	TableLayout,
	TableProperties,
	TableToolbar,
	TextTransformation,
	TextPartLanguage,
	TodoList,
	Underline,
	Undo,
	WordCount,
	type EditorConfig
} from 'ckeditor5';

import 'ckeditor5/ckeditor5.css';

// External plugins must use this exact runtime instead of bundling another
// CKEditor copy, which would trigger ckeditor-duplicated-modules errors.
( window as typeof window & { CKEDITOR: typeof CKEditor } ).CKEDITOR = CKEditor;

class CiviFileManager extends Plugin {
	public static get pluginName() {
		return 'CiviFileManager' as const;
	}

	public init(): void {
		const editor = this.editor;
		editor.ui.componentFactory.add( 'civiFileManager', locale => {
			const button = new ButtonView( locale );
			button.set( {
				label: 'CiviCRM file manager',
				icon: '<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M1.5 4.5A1.5 1.5 0 0 1 3 3h4l2 2h8A1.5 1.5 0 0 1 18.5 6.5v8A1.5 1.5 0 0 1 17 16H3a1.5 1.5 0 0 1-1.5-1.5v-10Zm2 2v7h13v-6.5h-8.3l-2-2H3.5v1.5Z"/></svg>',
				tooltip: true
			} );
			button.on( 'execute', () => editor.fire( 'civiFileManager:open' ) );
			return button;
		} );
	}
}

class Editor extends ClassicEditor {
	public static override builtinPlugins = [
		Alignment,
		AutoLink,
		Autoformat,
		Base64UploadAdapter,
		BlockQuote,
		Bookmark,
		Bold,
		CiviFileManager,
		CloudServices,
		Code,
		CodeBlock,
		Essentials,
		Emoji,
		FontBackgroundColor,
		FontColor,
		FontFamily,
		FontSize,
		FindAndReplace,
		Fullscreen,
		GeneralHtmlSupport,
		Heading,
		Highlight,
		HorizontalLine,
		HtmlEmbed,
		Image,
		ImageCaption,
		ImageResize,
		ImageStyle,
		ImageToolbar,
		ImageUpload,
		Indent,
		IndentBlock,
		Italic,
		Link,
		LinkImage,
		List,
		ListProperties,
		Markdown,
		MediaEmbed,
		Mention,
		PageBreak,
		PasteFromMarkdownExperimental,
		Paragraph,
		PasteFromOffice,
		PlainTableOutput,
		RemoveFormat,
		SourceEditing,
		SpecialCharacters,
		SpecialCharactersEssentials,
		ShowBlocks,
		Strikethrough,
		Style,
		Subscript,
		Superscript,
		Table,
		TableCaption,
		TableCellProperties,
		TableColumnResize,
		TableLayout,
		TableProperties,
		TableToolbar,
		TextTransformation,
		TextPartLanguage,
		TodoList,
		Underline,
		Undo,
		WordCount
	];

	public static override defaultConfig: EditorConfig = {
		licenseKey: 'GPL',
		language: 'en'
	};
}

export default Editor;
