export default function EmptyState({ loading }) {
    return (
        <div className="empty-state-wrap">
            <svg className="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            {loading ? (
                <>
                    <h3 className="empty-title">Loading Posts...</h3>
                    <p className="empty-text">Please wait while we fetch available posts.</p>
                </>
            ) : (
                <>
                    <h3 className="empty-title">No Posts Available</h3>
                    <p className="empty-text">There are no posts available to analyze at this time.</p>
                    <p className="empty-text">Please create or publish some posts first.</p>
                </>
            )}
        </div>
    );
}