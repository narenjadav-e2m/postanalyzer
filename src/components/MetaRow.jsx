import EditPopover from './EditPopover';

/**
 * Label + value row.
 *
 * Read-only mode: renders nothing for empty/null/zero values.
 * Editable mode (pass `edit`): always renders (so empty fields can be filled)
 * and wraps the value in an EditPopover.
 *
 * @param {object}  props
 * @param {string}  props.label
 * @param {*}       props.value
 * @param {boolean} [props.isHtml]
 * @param {object}  [props.edit]  { field, postId, attachmentId?, value, label?,
 *                                  multiline?, disabled?, disabledReason?, onSaved }
 */
export default function MetaRow({ label, value, isHtml = false, edit = null }) {
  const editable = !!edit;

  if (!editable && (value === undefined || value === null)) return null;
  const content = Array.isArray(value) ? value.join(', ') : String(value ?? '');
  const empty = content.trim() === '' || content === '0';
  if (!editable && empty) return null;

  const valueNode = isHtml
    ? <span className="rr-value" dangerouslySetInnerHTML={{ __html: content }} />
    : <span className="rr-value">{empty ? <em className="rr-empty">Not set</em> : content}</span>;

  return (
    <div className="rr-wrap">
      <strong className="rr-label">{label}</strong>
      {editable ? (
        <EditPopover
          field={edit.field}
          postId={edit.postId}
          attachmentId={edit.attachmentId}
          value={edit.value ?? (empty ? '' : content)}
          label={edit.label ?? label}
          multiline={edit.multiline}
          disabled={edit.disabled}
          disabledReason={edit.disabledReason}
          onSaved={edit.onSaved}
        >
          {valueNode}
        </EditPopover>
      ) : valueNode}
    </div>
  );
}
