import { PreviewIcon } from './icons.js';

const Preview = (props) => {
    const { pluginName } = props;
    return (
        <div className="am-recommended-block-preview" style={{
            display: 'flex',
            alignItems: 'center',
            textAlign: 'center',
            justifyContent: 'center',
            flexDirection: 'column',
            padding: '20px',
            height: '100%',
            width: '100%',
        }}>
            <PreviewIcon />
            <div className="am-recommended-block-preview-text">
                <p><strong>{pluginName + ' required'}</strong></p>
                <p>{'Add this block to install it.'}</p>
            </div>
        </div>
    );
}
export default Preview;