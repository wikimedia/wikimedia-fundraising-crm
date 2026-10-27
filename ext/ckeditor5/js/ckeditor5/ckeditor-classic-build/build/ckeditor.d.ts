/**
 * @license Copyright (c) 2014-2026, CKSource Holding sp. z o.o. All rights reserved.
 * For licensing, see LICENSE.md or https://ckeditor.com/legal/ckeditor-licensing-options
 */
import * as CKEditor from 'ckeditor5';
import { ClassicEditor, type EditorConfig } from 'ckeditor5';
import 'ckeditor5/ckeditor5.css';
declare class Editor extends ClassicEditor {
    static builtinPlugins: (typeof CKEditor.Alignment | typeof CKEditor.Autoformat | typeof CKEditor.Base64UploadAdapter | typeof CKEditor.BlockQuote | typeof CKEditor.Bold | typeof CKEditor.CKFinder | typeof CKEditor.CKFinderUploadAdapter | typeof CKEditor.CloudServices | typeof CKEditor.Code | typeof CKEditor.CodeBlock | typeof CKEditor.Essentials | typeof CKEditor.FontBackgroundColor | typeof CKEditor.FontColor | typeof CKEditor.FontFamily | typeof CKEditor.FontSize | typeof CKEditor.FindAndReplace | typeof CKEditor.Fullscreen | typeof CKEditor.GeneralHtmlSupport | typeof CKEditor.Heading | typeof CKEditor.Highlight | typeof CKEditor.HorizontalLine | typeof CKEditor.HtmlEmbed | typeof CKEditor.Image | typeof CKEditor.ImageCaption | typeof CKEditor.ImageResize | typeof CKEditor.ImageStyle | typeof CKEditor.ImageToolbar | typeof CKEditor.ImageUpload | typeof CKEditor.Indent | typeof CKEditor.Italic | typeof CKEditor.Link | typeof CKEditor.LinkImage | typeof CKEditor.List | typeof CKEditor.ListProperties | typeof CKEditor.MediaEmbed | typeof CKEditor.PageBreak | typeof CKEditor.Paragraph | typeof CKEditor.PasteFromOffice | typeof CKEditor.PlainTableOutput | typeof CKEditor.RemoveFormat | typeof CKEditor.SourceEditing | typeof CKEditor.SpecialCharacters | typeof CKEditor.SpecialCharactersEssentials | typeof CKEditor.Strikethrough | typeof CKEditor.Style | typeof CKEditor.Subscript | typeof CKEditor.Superscript | typeof CKEditor.Table | typeof CKEditor.TableCellProperties | typeof CKEditor.TableColumnResize | typeof CKEditor.TableLayout | typeof CKEditor.TableProperties | typeof CKEditor.TableToolbar | typeof CKEditor.TextTransformation | typeof CKEditor.Underline | typeof CKEditor.Undo)[];
    static defaultConfig: EditorConfig;
}
export default Editor;
