<?php 
namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\Blog;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    // Create a new blog post
    public function create(Request $request)
    {
        // Validate the incoming request
        $validator = $this->rules($request);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 400);
        }

        // Handle image upload
        $imagePath = '';
        if ($request->hasFile('featured_image')) {
            $imagePath = $request->file('featured_image')->store('blog_images', 'public');
        }

        // Create a new blog post
        $blog = Blog::create([
            'title' => $request->title,
            'slug' => Str::slug($request->slug),
            'excerpt' => $request->excerpt,
            'post_content' => $request->post_content,
            'featured_image' => $imagePath,
            'author_id' => Auth::id(),  // assuming logged-in user is the author
            'category_id' => $request->category_id,  // Added categoryId
            'tags'=>$request->tags,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Blog post created successfully',
            'data' => $blog,
        ], 201);
    }

    // Fetch all blog posts
    public function index()
    {
        $blogs = Blog::paginate(10);
        return response()->json([
            'status' => 'success',
            'data' => $blogs,
        ], 200);
    }

    // Show a specific blog post
    public function show($id)
    {
        $blog = Blog::find($id);

        if (!$blog) {
            return response()->json([
                'status' => 'error',
                'message' => 'Blog post not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $blog,
        ], 200);
    }

    // Update a blog post
    public function update(Request $request, $id)
    {
        $blog = Blog::find($id);

        if (!$blog) {
            return response()->json([
                'status' => 'error',
                'message' => 'Blog post not found',
            ], 404);
        }

        // Validate the request
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:256',
            'slug' => 'nullable|string|max:50|unique:blogs,slug,' . $blog->id,  // Exclude current blog from slug uniqueness check
            'excerpt' => 'nullable|string|max:255',
            'post_content' => 'nullable|string|max:20000',
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'tags' => 'nullable|string', //separated by comma like; #trendingnews, #news,#update etc
            'category_id' => 'nullable|integer', // Added categoryId validation
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 400);
        }

        // Update only the fields that are provided in the request
        if ($request->has('title')) {
            $blog->title = $request->title;
        }

        if ($request->has('slug')) {
            $blog->slug = Str::slug($request->slug);
        }

        if ($request->has('excerpt')) {
            $blog->excerpt = $request->excerpt;
        }

        if ($request->has('post_content')) {
            $blog->post_content = $request->post_content;
        }

        if ($request->has('category_id')) {
            $blog->category_id = $request->category_id;  // Update categoryId
        }

        // Handle image upload
        if ($request->hasFile('featured_image')) {
            // Delete old image if exists
            if ($blog->featured_image && \Storage::disk('public')->exists($blog->featured_image)) {
                \Storage::disk('public')->delete($blog->featured_image);
            }

            // Store new image
            $imagePath = $request->file('featured_image')->store('blog_images', 'public');
            $blog->featured_image = $imagePath;
        }

        $blog->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Blog post updated successfully',
            'data' => $blog,
        ], 200);
    }

    // Delete a blog post
    public function destroy($id)
    {
        $blog = Blog::find($id);

        if (!$blog) {
            return response()->json([
                'status' => 'error',
                'message' => 'Blog post not found',
            ], 404);
        }

        // Check if the blog has a featured image and delete it
        if ($blog->featured_image) {
            \Storage::disk('public')->delete($blog->featured_image);
        }

        // Delete blog post
        $blog->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Blog post deleted successfully',
        ], 200);
    }

    // Validation rules
    private function rules($data)
    {
        $rules = [
            'title' => 'required|string|max:256',
            'slug' => 'required|string|max:50|unique:blogs,slug',
            'excerpt' => 'required|string|max:255',
            'post_content' => 'required|string|max:20000',
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'tags' => 'required|string', //separated by comma
            'category_id' => 'required|integer', // Added categoryId validation rule
        ];

        return Validator::make($data->all(), $rules);
    }
}
